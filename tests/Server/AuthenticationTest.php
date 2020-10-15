<?php

namespace MadWizard\WebAuthn\Tests\Server;

use MadWizard\WebAuthn\Builder\ServerBuilder;
use MadWizard\WebAuthn\Config\RelyingParty;
use MadWizard\WebAuthn\Credential\CredentialId;
use MadWizard\WebAuthn\Credential\CredentialStoreInterface;
use MadWizard\WebAuthn\Credential\UserCredentialInterface;
use MadWizard\WebAuthn\Credential\UserHandle;
use MadWizard\WebAuthn\Crypto\CoseAlgorithm;
use MadWizard\WebAuthn\Crypto\Ec2Key;
use MadWizard\WebAuthn\Exception\ParseException;
use MadWizard\WebAuthn\Exception\UnsupportedException;
use MadWizard\WebAuthn\Exception\VerificationException;
use MadWizard\WebAuthn\Format\Base64UrlEncoding;
use MadWizard\WebAuthn\Format\ByteBuffer;
use MadWizard\WebAuthn\Json\JsonConverter;
use MadWizard\WebAuthn\Server\Authentication\AuthenticationOptions;
use MadWizard\WebAuthn\Server\WebAuthnServer;
use MadWizard\WebAuthn\Tests\Helper\AssertionDataHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use function hex2bin;

class AuthenticationTest extends TestCase
{
    /**
     * @var CredentialStoreInterface|MockObject
     */
    private $store;

    /**
     * @var WebAuthnServer|MockObject
     */
    private $server;

    public function testStartAuthentication()
    {
        $options = AuthenticationOptions::createForAnyUser();
        $userCredential = $this->createCredential();
        $options->addAllowCredential($userCredential->getCredentialId());
        $request = $this->server->startAuthentication($options);
        $clientOptions = $request->getClientOptions();
        $allowCredentials = $clientOptions->getAllowCredentials();
        self::assertCount(1, $allowCredentials);
        self::assertSame($userCredential->getCredentialId()->toString(), $allowCredentials[0]->getId()->getBase64Url());
        self::assertNull($allowCredentials[0]->getTransports());
        self::assertNull($clientOptions->getRpId());

        self::assertSame(
            [
                'challenge' => $request->getContext()->getChallenge()->getBase64Url(),
                'allowCredentials' => [
                        [
                            'type' => 'public-key',
                            'id' => $userCredential->getCredentialId()->toString(),
                        ],
                    ],
            ],
            $clientOptions->getJsonData()
        );
    }

    public function runAuth(AssertionDataHelper $helper): UserCredentialInterface
    {
        $credential = $this->createCredential();
        $this->store
            ->expects($this->any())
            ->method('findCredential')
            ->with($credential->getCredentialId())
            ->willReturn($credential);

        return $this->server->finishAuthentication(JsonConverter::decodeAssertionString($helper->getCredentialJson()), $helper->getContext())->getUserCredential();
    }

    public function testValidAssertion()
    {
        $helper = new AssertionDataHelper();

        $userCred = $this->runAuth($helper);
        self::assertSame(AssertionDataHelper::DEFAULT_CREDENTIAL_ID, $userCred->getCredentialId()->toString());
        /** @var Ec2Key $pubKey */
        $pubKey = $userCred->getPublicKey();

        self::assertSame(AssertionDataHelper::KEY_A_X, $pubKey->getX()->getHex());
        self::assertSame(AssertionDataHelper::KEY_A_Y, $pubKey->getY()->getHex());
    }

    public function testAllowedCredentials()
    {
        // SPEC 7.2.1 If the allowCredentials option was given when this authentication ceremony was initiated,
        // verify that credential.id identifies one of the public key credentials that were listed in allowCredentials.
        $helper = new AssertionDataHelper();

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~not in list of allowed credentials~i');

        $helper->setContextOptions(['allowedCredentials' => [Base64UrlEncoding::encode('different credential id')]]);
        $this->runAuth($helper);
    }

    public function testUserHandleOwner()
    {
        // SPEC 7.2.2 If credential.response.userHandle is present, verify that the user identified by this value is
        // the owner of the public key credential identified by credential.id.
        $helper = new AssertionDataHelper();

        $userHandle = Base64UrlEncoding::encode(hex2bin('123456'));

        $helper->setClientOptions(['userHandle' => $userHandle]);
        $userCred = $this->runAuth($helper);
        self::assertSame('123456', $userCred->getUserHandle()->toHex());
    }

    public function testUserHandleNotOwner()
    {
        // SPEC 7.2.2 If credential.response.userHandle is present, verify that the user identified by this value is
        // the owner of the public key credential identified by credential.id.
        $helper = new AssertionDataHelper();

        $userHandle = Base64UrlEncoding::encode(hex2bin('667788'));

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~does not belong to the user~i');

        $helper->setClientOptions(['userHandle' => $userHandle]);
        $this->runAuth($helper);
    }

    public function testInvalidClientDataJson()
    {
        // SPEC 7.2.5 JSON parse
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['makeWrongClientJson' => true]);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('~Unparseable client data JSON~i');

        $this->runAuth($helper);
    }

    public function testIncompleteClientDataJson()
    {
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['removeChallenge' => true]);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~missing data .+ clientData~i');

        $this->runAuth($helper);
    }

    public function testBOMClientDataJson()
    {
        // SPEC 7.2.5 JSON parse
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['includeJsonBom' => true]);

        $this->runAuth($helper);

        // Check no exceptions
        self::assertTrue(true);
    }

    public function testCredentialType()
    {
        // SPEC 7.2.6 Verify that the value of C.type is the string webauthn.get.
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['type' => 'webauthn.create']);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~webauthn\.get~i');

        $this->runAuth($helper);
    }

    public function testSameChallenge()
    {
        // SPEC 7.2.8 Verify that the value of C.challenge matches the challenge that was sent to the authenticator
        // in the PublicKeyCredentialRequestOptions passed to the get() call.
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['challenge' => Base64UrlEncoding::encode('differentchallenge123456789')]);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~challenge.+does not match~i');

        $this->runAuth($helper);
    }

    public function testSameOrigin()
    {
        // SPEC 7.2.9 Verify that the value of C.origin matches the Relying Party's origin.
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['origin' => 'http://example.com']);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~origin.+does not match~i');

        $this->runAuth($helper);
    }

    public function testRpIdMatches()
    {
        // SPEC 7.2.11 Verify that the rpIdHash in aData is the SHA-256 hash of the RP ID expected by the Relying Party.
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['rpId' => 'not-localhost']);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~rpIdHash was not correct~i');

        $this->runAuth($helper);
    }

    public function testTokenBinding()
    {
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['tokenBinding' => ['status' => 'present', 'id' => '123456']]);

        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessageMatches('~token binding is not yet supported~i');

        $this->runAuth($helper);
    }

    public function testInvalidTokenBindingData()
    {
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['tokenBinding' => [true, false]]);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~unexpected .+ tokenBinding~i');

        $this->runAuth($helper);
    }

    public function testInvalidTokenBindingStatus()
    {
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['tokenBinding' => ['status' => 'invalidstatus']]);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessageMatches('~status.+invalid~i');

        $this->runAuth($helper);
    }

    public function testNeutralTokenBindingStatus()
    {
        $helper = new AssertionDataHelper();

        $helper->setClientOptions(['tokenBinding' => ['status' => 'supported']]);

        $this->runAuth($helper);

        // Check no exceptions
        self::assertTrue(true);
    }

    protected function setUp(): void
    {
        $rp = new RelyingParty('Example', 'https://example.com');
        $this->store = $this->createMock(CredentialStoreInterface::class);

        $this->store->expects($this->any())
            ->method('getSignatureCounter')
            ->willReturn(8);

        $this->server = (new ServerBuilder())
            ->setRelyingParty($rp)
            ->setCredentialStore($this->store)
            ->build();
    }

    private function createCredential(): UserCredentialInterface
    {
        /**
         * @var $cred UserCredentialInterface|MockObject
         */
        $cred = $this->createMock(UserCredentialInterface::class);

        $cred->expects($this->any())
            ->method('getCredentialId')
            ->willReturn(CredentialId::fromString(AssertionDataHelper::DEFAULT_CREDENTIAL_ID));

        $cred->expects($this->any())
            ->method('getPublicKey')
            ->willReturn(
                new Ec2Key(
                    ByteBuffer::fromHex(AssertionDataHelper::KEY_A_X),
                    ByteBuffer::fromHex(AssertionDataHelper::KEY_A_Y),
                    Ec2Key::CURVE_P256,
                    CoseAlgorithm::ES256
                )
            );

        $cred->expects($this->any())
            ->method('getUserHandle')
            ->willReturn(UserHandle::fromHex('123456'));

        return $cred;
    }
}
