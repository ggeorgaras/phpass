<?php
/**
 * PHP Password Library
 *
 * @package PHPass\Hashes
 * @category Cryptography
 * @author Ryan Chouinard <rchouinard at gmail.com>
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 * @link https://github.com/rchouinard/phpass Project at GitHub
 */

/**
 * @namespace
 */
namespace Phpass\Hash\Adapter;
use Phpass\Exception\InvalidArgumentException,
    Phpass\Exception\RuntimeException;

/**
 * PBKDF2 hash adapter
 *
 * @package PHPass\Hashes
 * @category Cryptography
 * @author Ryan Chouinard <rchouinard at gmail.com>
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 * @link https://github.com/rchouinard/phpass Project at GitHub
 */
class Pbkdf2 extends Base
{

    /**
     * Hashing algorithm used by the PBKDF2 implementation.
     *
     * @var string
     */
    protected $_algo = 'sha256';

    /**
     * Logarithmic cost value used to generate new hash values.
     *
     * @var integer
     */
    protected $_iterationCountLog2 = 12;

    /**
     * Return a hashed string.
     *
     * @param string $password
     *   The string to be hashed.
     * @param string $salt
     *   An optional salt string to base the hashing on. If not provided, a
     *   suitable string is generated by the adapter.
     * @return string
     *   Returns the hashed string. On failure, a standard crypt error string
     *   is returned which is guaranteed to differ from the salt.
     * @throws RuntimeException
     *   A RuntimeException is thrown on failure if
     *   self::$_throwExceptionOnFailure is true.
     */
    public function crypt($password, $salt = null)
    {
        if (!$salt) {
            $salt = $this->genSalt();
        }

        $hash = '*0';
        if ($this->verify($salt)) {
            $count = 1 << strpos($this->_itoa64, $salt[6]);
            $checksum = $this->_pbkdf2($password, substr($salt, 7, 8), $count, 24, $this->_algo);
            $hash = rtrim(substr($salt, 0, 16), '$') . '$' . $this->_encode64($checksum, 24);
        }

        if (!$this->verifyHash($hash)) {
            $hash = ($salt != '*0') ? '*0' : '*1';
            if ($this->_throwExceptionOnFailure) {
                throw new RuntimeException('Failed generating a valid hash', $hash);
            }
        }

        return $hash;
    }

    /**
     * Generate a salt string compatible with this adapter.
     *
     * @param string $input
     *   Optional random 48-bit string to use when generating the salt.
     * @return string
     *   Returns the generated salt string.
     */
    public function genSalt($input = null)
    {
        if (!$input) {
            $input = $this->_getRandomBytes(6);
        }

        // PKCS #5, version 2
        // Python implementation uses $p5k2$, but we're not using a compatible
        // string. https://www.dlitz.net/software/python-pbkdf2/
        $identifier = 'p5v2';

        // Iteration count between 1 and 1,073,741,824
        $count = $this->_itoa64[min(max($this->_iterationCountLog2, 1), 30)];

        // 8-byte (64-bit) salt value, as recommended by the standard
        $salt = $this->_encode64($input, 6);

        // $p5v2$CSSSSSSSS$
        return '$' . $identifier . '$' . $count . $salt . '$';
    }

    /**
     * Set adapter options.
     *
     * Expects an associative array of option keys and values used to configure
     * this adapter.
     *
     * <dl>
     *   <dt>iterationCountLog2</dt>
     *     <dd>Base-2 logarithm of the iteration count for the underlying
     *     PBKDF2 hashing algorithm. Must be in range 1 - 30. Defaults to
     *     12.</dd>
     * </dl>
     *
     * @param Array $options
     *   Associative array of adapter options.
     * @return self
     *   Returns an instance of self to support method chaining.
     * @throws InvalidArgumentException
     *   Throws an InvalidArgumentException if a provided option key contains
     *   an invalid value.
     * @see Base::setOptions()
     */
    public function setOptions(Array $options)
    {
        parent::setOptions($options);
        $options = array_change_key_case($options, CASE_LOWER);

        foreach ($options as $key => $value) {
            switch ($key) {
                case 'iterationcountlog2':
                    $value = (int) $value;
                    if ($value < 1 || $value > 30) {
                        throw new InvalidArgumentException('Iteration count must be between 4 and 31');
                    }
                    $this->_iterationCountLog2 = $value;
                    break;
                default:
                    break;
            }
        }

        return $this;
    }

    /**
     * Check if a hash string is valid for the current adapter.
     *
     * @since 2.1.0
     * @param string $input
     *   Hash string to verify.
     * @return boolean
     *   Returns true if the input string is a valid hash value, false
     *   otherwise.
     */
    public function verifyHash($input)
    {
        return ($this->verifySalt(substr($input, 0, -32)) && 1 === preg_match('/^[\.\/0-9A-Za-z]{32}$/', substr($input, -32)));
    }

    /**
     * Check if a salt string is valid for the current adapter.
     *
     * @since 2.1.0
     * @param string $input
     *   Salt string to verify.
     * @return boolean
     *   Returns true if the input string is a valid salt value, false
     *   otherwise.
     */
    public function verifySalt($input)
    {
        return (1 === preg_match('/^\$p5v2\$[\.\/0-9A-Za-z]{1}[\.\/0-9A-Za-z]{8}\$?$/', $input));
    }

    /**
     * Internal implementation of PKCS #5 v2.0.
     *
     * This implementation passes tests using vectors given in RFC 6070 s.2,
     * PBKDF2 HMAC-SHA1 Test Vectors. Vectors given for PBKDF2 HMAC-SHA2 at
     * http://stackoverflow.com/questions/5130513 also pass.
     *
     * @param string $password
     *   The string to be hashed.
     * @param string $salt
     *   Salt value used by the HMAC function.
     * @param integer $iterationCount
     *   Number of iterations for key stretching.
     * @param integer $keyLength
     *   Length of derived key.
     * @param string $algo
     *   Algorithm to use when generating HMAC digest.
     * @return string
     *   Returns the raw hash string.
     */
    protected function _pbkdf2($password, $salt, $iterationCount = 1000, $keyLength = 20, $algo = 'sha1')
    {
        $hashLength = strlen(hash($algo, null, true));
        $keyBlocks = ceil($keyLength / $hashLength);
        $derivedKey = '';

        for ($block = 1; $block <= $keyBlocks; ++$block) {
            $iteratedBlock = $currentBlock = hash_hmac($algo, $salt . pack('N', $block), $password, true);
            for ($iteration = 1; $iteration < $iterationCount; ++$iteration) {
                $iteratedBlock ^= $currentBlock = hash_hmac($algo, $currentBlock, $password, true);
            }

            $derivedKey .= $iteratedBlock;
        }

        return substr($derivedKey, 0, $keyLength);
    }

}