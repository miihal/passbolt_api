<?php
/**
 * Passbolt ~ Open source password manager for teams
 * Copyright (c) Passbolt SA (https://www.passbolt.com)
 *
 * Licensed under GNU Affero General Public License version 3 of the or any later version.
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Passbolt SA (https://www.passbolt.com)
 * @license       https://opensource.org/licenses/AGPL-3.0 AGPL License
 * @link          https://www.passbolt.com Passbolt(tm)
 * @since         2.0.0
 */
namespace App\Utility\OpenPGP\Backends;

//use \Exception as Exception;
use App\Utility\OpenPGP\OpenPGPBackend;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use \OpenPGP as OpenPGP;
use \OpenPGP_Message as OpenPGP_Message;
use \OpenPGP_PublicKeyPacket as OpenPGP_PublicKeyPacket;
use \OpenPGP_SecretKeyPacket as OpenPGP_SecretKeyPacket;

/**
 * Gpg wrapper utility
 *
 * This class provides tools for Gpg operations.
 * It is based on 2 different GPG libraries : Php Gnupg, and Openpgp-php
 *
 * We use 2 libraries instead of ones for the following reasons:
 *
 *  - bugs : Some operations of Php Gnupg have been seen to provoke segmentation fault in the past.
 *  Such segmentation faults happened when we try to import a key with an invalid format for instance.
 *  As a failsafe, since we still need to validate keys, we rely on Openpgp-php for pre-flying such operations
 *  and mitigate the associated security risks.
 *
 *  - Velocity : Openpgp-php is library implemented in PHP which is not as fast as Php Gnupg.
 *  So for all encryption and decryption operations, we will prefer Php Gnupg.
 *
 */
class Gnupg implements OpenPGPBackend
{
    /**
     * @var string fingerprint of the key set to decrypt
     */
    private $_decryptKey;

    /**
     * @var string fingerprint of the key set to encrypt
     */
    private $_encryptKey;

    /**
     * @var string fingerprint of the key set to encrypt
     */
    private $_signKey;

    /**
     * Gpg object.
     */
    protected $_gpg = null;

    /**
     * OpenPGP ASCII armored message/key marker
     */
    const MESSAGE_MARKER = 'PGP MESSAGE';
    const PUBLIC_KEY_MARKER = 'PGP PUBLIC KEY BLOCK';
    const PRIVATE_KEY_MARKER = 'PGP PRIVATE KEY BLOCK';

    /**
     * Constructor.
     *
     * @throws Exception
     */
    public function __construct()
    {
        if (!extension_loaded('gnupg')) {
            throw new Exception('PHP Gnupg library is not installed.');
        }

        if (Configure::read('passbolt.gpg.putenv')) {
            putenv('GNUPGHOME=' . Configure::read('passbolt.gpg.keyring'));
        }

        $this->_gpg = new \gnupg();
        $this->_gpg->seterrormode(\gnupg::ERROR_EXCEPTION);
        $this->_encryptKey = null;
        $this->_decryptKey = null;
        $this->_signKey = null;
    }

    /**
     * Set a key for encryption.
     *
     * @param string $armoredKey ASCII armored key data
     * @throws Exception if the key cannot be used to encrypt
     * @return bool true if success
     */
    public function setEncryptKey(string $armoredKey)
    {
        // Get the key info.
        $encryptKeyInfo = $this->getPublicKeyInfo($armoredKey);
        $fingerprint = $encryptKeyInfo['fingerprint'];

        try {
            $this->_gpg->addencryptkey($fingerprint);
            $this->_encryptKey = $fingerprint;
        } catch (\Exception $e) {
            // It didn't work, maybe only key is not in the keyring
            // we import the key and retry
            $this->importKeyIntoKeyring($armoredKey);
            try {
                $this->_gpg->addencryptkey($fingerprint);
                $this->_encryptKey = $fingerprint;
            } catch (\Exception $e) {
                $this->_encryptKey = null;
                throw new Exception(__('The key {0} cannot be used to encrypt.', $fingerprint));
            }
        }

        return true;
    }

    /**
     * Set a key for encryption.
     *
     * @param string $fingerprint fingerprint
     * @throws Exception if key is not present in keyring
     * @throws Exception if there was an issue to use the key to encrypt
     * @return bool true if success
     */
    public function setEncryptKeyFromFingerprint(string $fingerprint)
    {
        $this->assertKeyInKeyring($fingerprint);
        try {
            $this->_gpg->addencryptkey($fingerprint);
            $this->_encryptKey = $fingerprint;
        } catch (\Exception $e) {
            $this->_encryptKey = null;
            throw new Exception(__('The key {0} cannot be used to encrypt.', $fingerprint));
        }

        return true;
    }

    /**
     * Set a key for decryption.
     *
     * @param string $armoredKey ASCII armored key data
     * @param string $passphrase to decrypt secret key
     * @throws Exception if the key cannot be found in the keyring
     * @throws Exception if the key is using a passphrase
     * @throws Exception if the key cannot be used to decrypt
     * @return bool true if success
     */
    public function setDecryptKey(string $armoredKey, string $passphrase)
    {
        $this->assertPassphraseEmpty($passphrase);

        // Get the key info.
        $decryptKeyInfo = $this->getKeyInfo($armoredKey);
        $fingerprint = $decryptKeyInfo['fingerprint'];

        try {
            $this->_gpg->adddecryptkey($fingerprint, $passphrase);
            $this->_decryptKey = $fingerprint;
        } catch (\Exception $e) {
            // It didn't work, maybe only key is not in the keyring
            // we import the key and retry
            $this->importKeyIntoKeyring($armoredKey);
            try {
                $this->_gpg->adddecryptkey($fingerprint, $passphrase);
                $this->_decryptKey = $fingerprint;
            } catch (\Exception $e) {
                $this->_decryptKey = null;
                $msg = __('The key {0} cannot be used to decrypt.', $fingerprint);
                throw new Exception($msg . ' ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Set a key for decryption.
     *
     * @param string $fingerprint fingerprint of a key in the keyring
     * @param string $passphrase to decrypt secret key
     * @throws Exception if the key cannot be found in the keyring
     * @throws Exception if the key is using a passphrase
     * @throws Exception if the key cannot be used to decrypt
     * @return bool true if success
     */
    public function setDecryptKeyFromFingerprint(string $fingerprint, string $passphrase)
    {
        $this->assertPassphraseEmpty($passphrase);

        try {
            $this->_gpg->adddecryptkey($fingerprint, $passphrase);
            $this->_decryptKey = $fingerprint;
        } catch (\Exception $e) {
            $this->_decryptKey = null;
            throw new Exception('The key {0} cannot be used to decrypt.', $fingerprint);
        }

        return true;
    }

    /**
     * Set a key for signing.
     *
     * @param string $armoredKey ASCII armored key data
     * @param string $passphrase passphrase
     * @throws Exception if the key is not already in the keyring
     * @throws Exception if the passphrase is not empty
     * @throws Exception if the key cannot be used for signing
     * @return bool
     * @throws Exception
     */
    public function setSignKey(string $armoredKey, string $passphrase)
    {
        $this->assertPassphraseEmpty($passphrase);

        $signKeyInfo = $this->getKeyInfo($armoredKey);
        $fingerprint = $signKeyInfo['fingerprint'];

        try {
            // The key is in the keyring try to use it as a sign key
            $this->_gpg->addsignkey($fingerprint, $passphrase);
            $this->_signKey = $fingerprint;
        } catch (\Exception $e) {
            // It didn't work, maybe only public key was in the keyring
            // we try to re-import the key
            $this->importKeyIntoKeyring($armoredKey);
            try {
                $this->_gpg->addsignkey($fingerprint, $passphrase);
                $this->_signKey = $fingerprint;
            } catch (\Exception $e) {
                $this->_signKey = null;
                $msg = __('Could not use key {0} for signing.', $fingerprint);
                throw new Exception($msg . ' ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Set key to be used for signing
     *
     * @throws Exception if the key is not already in the keyring
     * @throws Exception if the passphrase is not empty
     * @throws Exception if the key cannot be used for signing
     * @param string $fingerprint fingerprint
     * @param string $passphrase passphrase
     * @return true if success
     */
    public function setSignKeyFromFingerprint(string $fingerprint, string $passphrase)
    {
        $this->assertPassphraseEmpty($passphrase);

        try {
            $this->_gpg->addsignkey($fingerprint, $passphrase);
            $this->_signKey = $fingerprint;
        } catch (\Exception $e) {
            $this->_signKey = null;
            $msg = __('Could not use key {0} for signing.', $fingerprint);
            throw new Exception($msg . ' ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Get the gpg marker.
     *
     * @param string $armored ASCII armored gpg data
     * @return mixed
     * @throws Exception
     */
    private function getGpgMarker(string $armored)
    {
        $isMarker = preg_match('/-(BEGIN )*([A-Z0-9 ]+)-/', $armored, $values);
        if (!$isMarker || !isset($values[2])) {
            throw new Exception(__('No OpenPGP marker found.'));
        }

        return $values[2];
    }

    /**
     * Check if an ASCII armored public key is parsable
     *
     * To do this, we try to unarmor the key. If the operation is successful, then we consider that
     * the key is a valid one.
     *
     * @param string $armoredKey ASCII armored key data
     * @return bool true if valid, false otherwise
     */
    public function isParsableArmoredPublicKey(string $armoredKey)
    {
        try {
            $this->assertGpgMarker($armoredKey, self::PUBLIC_KEY_MARKER);
        } catch (Exception $e) {
            return false;
        }

        // If we don't manage to unarmor the key, we consider it's not a valid one.
        $keyUnarmored = OpenPGP::unarmor($armoredKey, self::PUBLIC_KEY_MARKER);
        if ($keyUnarmored === false) {
            return false;
        }

        // Try to parse the key
        // @codingStandardsIgnoreStart
        $publicKey = @(\OpenPGP_PublicKeyPacket::parse($keyUnarmored));
        // @codingStandardsIgnoreEnd
        if (empty($publicKey) || empty($publicKey->fingerprint) || empty($publicKey->key)) {
            return false;
        }

        return true;
    }

    /**
     * Check if an ASCII armored private key is parsable
     *
     * To do this, we try to unarmor the key. If the operation is successful, then we consider that
     * the key is a valid one.
     *
     * @param  string $armoredKey ASCII armored key data
     * @return bool true if parsable false otherwise
     */
    public function isParsableArmoredPrivateKey(string $armoredKey)
    {
        try {
            $this->assertGpgMarker($armoredKey, self::PRIVATE_KEY_MARKER);
        } catch (Exception $e) {
            return false;
        }

        // If we don't manage to unarmor the key, we consider it's not a valid one.
        $keyUnarmored = OpenPGP::unarmor($armoredKey, self::PRIVATE_KEY_MARKER);
        if ($keyUnarmored == false) {
            return false;
        }

        // Try to parse the key
        // @codingStandardsIgnoreStart
        $publicKey = @(OpenPGP_SecretKeyPacket::parse($keyUnarmored));
        // @codingStandardsIgnoreEnd
        if (empty($publicKey) || empty($publicKey->fingerprint) || empty($publicKey->key)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a message is valid.
     *
     * To do this, we try to unarmor the message. If the operation is successful, then we consider that
     * the message is a valid one.
     *
     * @param string $armored ASCII armored message data
     * @return bool true if valid, false otherwise
     */
    public function isValidMessage(string $armored)
    {
        try {
            $this->assertGpgMarker($armored, self::MESSAGE_MARKER);
        } catch (Exception $e) {
            return false;
        }
        $unarmored = OpenPGP::unarmor($armored, self::MESSAGE_MARKER);

        return !($unarmored === false || $unarmored === null);
    }

    /**
     * Get public key information.
     *
     * @param string $armoredKey the ASCII armored key block
     * @throws Exception if the armored key cannot be parsed
     * @return array key information (see getKeyInfo)
     */
    public function getPublicKeyInfo(string $armoredKey)
    {
        if ($this->isParsableArmoredPublicKey($armoredKey) === false
            && $this->isParsableArmoredPrivateKey($armoredKey) === false) {
            throw new Exception(__('The public key could not be parsed.'));
        }

        return $this->getKeyInfo($armoredKey);
    }

    /**
     * Get key information
     *
     * Extract the information from the key and return them in an array:
     *  - fingerprint   : fingerprint of the key, string(40)
     *  - bits          : size / number of bits (int)
     *  - type          : algorithm used by the key (RSA, ELGAMAL, DSA, etc..)
     *  - key_id        : key id, string(8)
     *  - key_created   : date of creation of the key, timestamp
     *  - uid           : user id of the key following gpg standard (usually name surname (comment) <email>), string
     *  - expires       : expiration date or empty if no expiration date, timestamp
     *
     * Important note : this function is using OpenPgp-PHP library instead of php-gnupg to pre-validate the key.
     *
     * @param string $armoredKey the ASCII armored key block
     * @return array as described above
     */
    public function getKeyInfo(string $armoredKey)
    {
        // Unarmor the key.
        $keyUnarmored = OpenPGP::unarmor($armoredKey, $this->getGpgMarker($armoredKey));

        // Get the message.
        $msg = OpenPGP_Message::parse($keyUnarmored);

        // Parse public key.
        $publicKey = OpenPGP_PublicKeyPacket::parse($keyUnarmored);

        // Get Packets for public key.
        $publicKeyPacket = $msg->packets[0];

        // If the packet is not a valid publicKey Packet, then we can't retrieve the uid.
        if (!$publicKeyPacket instanceof OpenPGP_PublicKeyPacket) {
            throw new Exception(__('Invalid key. No public key package found.'));
        }

        // Get userId.
        $userIds = [];
        foreach ($msg->signatures() as $signatures) {
            foreach ($signatures as $signature) {
                if ($signature instanceof \OpenPGP_UserIDPacket) {
                    $userIds[] = sprintf('%s', $signature);
                }
            }
        }

        // Retrieve algorithm type.
        $type = OpenPGP_PublicKeyPacket::$algorithms[$publicKeyPacket->algorithm];

        // Retrieve key size.
        $bits = 0;
        if (isset(OpenPGP_PublicKeyPacket::$key_fields[$publicKeyPacket->algorithm])) {
            $keyFirstElt = OpenPGP_PublicKeyPacket::$key_fields[$publicKeyPacket->algorithm][0];
            $bits = OpenPGP::bitlength($publicKeyPacket->key[$keyFirstElt]);
        }

        // Build key information array.
        $info = [
            'fingerprint' => $publicKeyPacket->fingerprint(),
            'bits' => $bits,
            'type' => $type,
            'key_id' => $publicKeyPacket->key_id,
            'key_created' => $publicKey->timestamp,
            'uid' => $userIds[0],
            'expires' => $publicKeyPacket->expires($msg),
        ];

        return $info;
    }

    /**
     * Get key information from keyring
     *
     * @param string $fingerprint key fingerpint
     * @return array|false
     */
    public function getKeyInfoFromKeyring(string $fingerprint)
    {
        try {
            $results = $this->_gpg->keyinfo($fingerprint);
        } catch (\Exception $e) {
            return false;
        }
        if (empty($results)) {
            return false;
        }

        return $results;
    }

    /**
     * Is key currently in keyring
     *
     * @param string $fingerprint fingerprint
     * @return bool true if in keyring false otherwise
     */
    public function isKeyInKeyring(string $fingerprint)
    {
        $results = $this->getKeyInfoFromKeyring($fingerprint);

        return !($results === false);
    }

    /**
     * Import a key into the local keyring.
     *
     * @param string $armoredKey the ASCII armored key block
     * @throws Exception if the key could not be imported
     * @return array information about the key
     */
    public function importKeyIntoKeyring(string $armoredKey)
    {
        $msg = __('Could not import the key.');
        try {
            $import = $this->_gpg->import($armoredKey);
        } catch (\Exception $e) {
            throw new Exception($msg);
        }
        if (!is_array($import)) {
            throw new Exception($msg);
        }

        return $import;
    }

    /**
     * Encrypt a text and optionally sign it too
     * Do not forget to add a key to encrypt and optionally to sign
     *
     * @param string $text plain text to be encrypted.
     * @param bool $sign whether the encrypted message should be signed.
     * @throws Exception if no key was set to encrypt and optionally to sign
     * @throws Exception if there is an issue with the key to encrypt and optionally to sign
     * @return string encrypted text
     */
    public function encrypt(string $text, bool $sign = false)
    {
        $this->assertEncryptKey();
        if ($sign === true) {
            $msg = __('Could not use the key to sign and encrypt.');
            $this->assertSignKey();
            $this->assertEncryptKey();
            try {
                $encryptedText = $this->_gpg->encryptsign($text);
            } catch (\Exception $e) {
                throw new Exception($msg . $e->getMessage());
            }
            if ($encryptedText === false) {
                throw new Exception($msg);
            }
        } else {
            $msg = __('Could not use the key to encrypt.');
            $this->assertEncryptKey();
            try {
                $encryptedText = $this->_gpg->encrypt($text);
            } catch (\Exception $e) {
                throw new Exception($msg . $e->getMessage());
            }
            if ($encryptedText === false) {
                throw new Exception($msg);
            }
        }

        return $encryptedText;
    }

    /**
     * Decrypt a text
     *
     * @param string $text ASCII armored encrypted text to be decrypted.
     * @param bool $verifySignature should signature be verified
     * @param array $signatureInfo signature data (optional)
     * @throws Exception
     * @return string decrypted text
     */
    public function decrypt(string $text, bool $verifySignature = false, array &$signatureInfo = [])
    {
        $decrypted = false;
        $this->assertDecryptKey();
        try {
            if ($verifySignature === false) {
                $decrypted = $this->_gpg->decrypt($text);
            } else {
                $signatureInfo = $this->_gpg->decryptverify($text, $decrypted);
            }
        } catch (\Exception $e) {
            throw new Exception(__('Decryption failed.'));
        }
        if ($decrypted === false) {
            throw new Exception(__('Decryption failed.'));
        }

        return $decrypted;
    }

    /**
     * Sign a text.
     *
     * @param string $text plain text to be encrypted.
     * @throws Exception if no key was set to sign
     * @throws Exception if there is an issue with the key to sign
     * @return string signed text
     */
    public function sign(string $text)
    {
        $msg = __('Could not use the key to sign.');
        $this->assertSignKey();
        try {
            $signedText = $this->_gpg->encryptsign($text);
        } catch (\Exception $e) {
            throw new Exception($msg . $e->getMessage());
        }
        if ($signedText === false) {
            throw new Exception($msg);
        }

        return $signedText;
    }

    /**
     * Verify an encrypted signed text.
     *
     * @param string $text encrypted signed text to be verified.
     * @param string $signature (optional) the signature. In the case where the signature is not included in the encrypted text.
     * @throws Exception if there is an issue while verifying the text
     * @return string signature data
     */
    public function verify(string $text, string $signature = "")
    {
        $msg = __('Could not verify the message.');
        try {
            if (empty($signature)) {
                $verifySignature = $this->_gpg->verify($text);
            } else {
                $verifySignature = $this->_gpg->verify($text, signature);
            }
        } catch (\Exception $e) {
            throw new Exception($msg . $e->getMessage());
        }
        if ($verifySignature === false) {
            throw new Exception($msg);
        }

        return $verifySignature;
    }

    /**
     * Verify a clearsigned text.
     *
     * @param string $text clearsigned text to be verified.
     * @param string $plainText (optional) The plain text. If this optional parameter is passed, it is filled with the plain text.
     * @throws Exception if there is an issue while verifying the text
     * @return string signature data
     */
    public function verifyClearsignedText(string $text, string &$plainText = "")
    {
        $msg = __('Could not verify the message.');
        try {
            $verifySignature = $this->_gpg->verify($text, false, $plainText);
        } catch (\Exception $e) {
            throw new Exception($msg . $e->getMessage());
        }
        if ($verifySignature === false) {
            throw new Exception($msg);
        }

        return $verifySignature;
    }

    /**
     * Assert the passphrase is empty
     *
     * @param string $passphrase passphrase
     * @throws Exception if passphrase is not empty
     * @return void
     */
    public function assertPassphraseEmpty(string $passphrase)
    {
        if ($passphrase !== '') {
            throw new Exception('Secret keys with a passphrase are not supported.');
        }
    }

    /**
     * Assert key is in the keyring
     *
     * @param string $fingerprint fingerprint
     * @return void
     */
    public function assertKeyInKeyring(string $fingerprint)
    {
        if (!$this->isKeyInKeyring($fingerprint)) {
            throw new Exception(__('The key {0} was not found in the keyring', $fingerprint));
        }
    }

    /**
     * Assert the signature key is set
     *
     * @throws Exception if not signature key is set
     * @return void
     */
    public function assertSignKey()
    {
        if (empty($this->_signKey)) {
            throw new Exception(__('Can not sign without a key. Set a sign key first.'));
        }
    }

    /**
     * Check if an encryption key is set
     *
     * @throws Exception if no encryption key is set
     * @return void
     */
    public function assertEncryptKey()
    {
        if (empty($this->_encryptKey)) {
            throw new Exception(__('Can not encrypt without a key. Set a public key first.'));
        }
    }

    /**
     * Check if a decrypt key is set
     *
     * @throws Exception if no decryption key is set
     * @return void
     */
    public function assertDecryptKey()
    {
        if (empty($this->_decryptKey)) {
            throw new Exception(__('Can not decrypt without a key. Set a secret key first.'));
        }
    }

    /**
     * Assert an armored message/key marker is present in plaintext
     *
     * @param string $armoredText message or key in ASCII armored format
     * @param string $marker a message delimiter like 'PGP MESSAGE'
     * @throws Exception if the armored message marker does not match the one provided
     * @return bool true if successful
     */
    public function assertGpgMarker(string $armoredText, string $marker)
    {
        $msg = __('This is not a valid OpenPGP armored message/key marker');
        try {
            $m = $this->getGpgMarker($armoredText);
        } catch (Exception $e) {
            throw new Exception($msg);
        }
        if ($m !== $marker) {
            throw new Exception($msg);
        }

        return true;
    }
}