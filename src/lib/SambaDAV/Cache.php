<?php	// $Format:SambaDAV: commit %h @ %cd$

# Copyright (C) 2013, 2014  Bokxing IT, http://www.bokxing-it.nl
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as
# published by the Free Software Foundation, either version 3 of the
# License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
#
# Project page: <https://github.com/1afa/sambadav/>

namespace SambaDAV;

abstract class Cache
{
	// Cipher used to protect cache contents. AES-256-GCM is an authenticated
	// mode: a cache file that was tampered with, or that was encrypted under
	// a different key, fails to decrypt instead of yielding garbage.
	// (The mcrypt extension, used by earlier versions, was removed in PHP 7.2.)
	const CIPHER    = 'aes-256-gcm';
	const IV_SIZE   = 12;	// bytes; the recommended nonce size for GCM
	const SALT_SIZE = 16;	// bytes
	const TAG_SIZE  = 16;	// bytes

	// Write $data to cache as $key, good for $timeout seconds.
	// Returns true/false.
	abstract protected function write ($key, $data, $timeout);

	// Read $data from cache as $key, if younger than timeout.
	// Returns true/false.
	abstract protected function read ($key, &$data, $timeout);

	// Delete data under $key from cache:
	abstract protected function delete ($key);

	// Run a cleaning pass on the cache:
	abstract protected function clean ();

	private function
	key ($auth, $callableName, $uri)
	{
		// Key is unique for function, URI, username and password.
		// Include password here to avoid decode errors when someone
		// logs in with a valid username and wrong password. If the
		// password would not contribute to the filename, we would try
		// to open the existing cache file and get a decode error:
		return hash('sha256', $auth->user . $auth->pass . $callableName . $uri->uriFull());
	}

	public function
	serialize ($data, &$raw, $user_key)
	{
		// Serialize the raw data:
		if (($ser = serialize($data)) === false) {
			return false;
		}
		// Compress:
		if (($com = gzcompress($ser)) === false) {
			return false;
		}
		// Encrypt:
		if (($raw = $this->encrypt($com, $user_key)) === false) {
			return false;
		}
		return true;
	}

	public function
	unserialize (&$data, $raw, $user_key)
	{
		// Decode:
		if (($dec = $this->decrypt($raw, $user_key)) === false) {
			return false;
		}
		// Uncompress:
		if (($unc = @gzuncompress($dec)) === false) {
			return false;
		}
		// Unserialize to get plain data. The cache only ever holds plain
		// arrays and scalars, so refuse to instantiate any objects:
		if (($data = @unserialize($unc, ['allowed_classes' => false])) === false) {
			return false;
		}
		return true;
	}

	private function
	deriveKey ($salt, $user_key)
	{
		// The key is derived from the user's secret and a random per-file
		// salt, so that the same secret never yields the same key twice.
		// HKDF-SHA256 gives us a 32 byte key, as AES-256 needs:
		return hash_hkdf('sha256', $user_key, 32, 'sambadav-cache', $salt);
	}

	public function
	encrypt ($data, $user_key)
	{
		if ($user_key === '' || $user_key === null) {
			return false;
		}
		$iv = random_bytes(self::IV_SIZE);
		$salt = random_bytes(self::SALT_SIZE);
		$key = $this->deriveKey($salt, $user_key);
		$tag = '';

		$ciphertext = openssl_encrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_SIZE);

		if ($ciphertext === false || strlen($tag) !== self::TAG_SIZE) {
			return false;
		}
		// Prepend the IV, the salt and the authentication tag to the data;
		// none of them are secret:
		return $iv . $salt . $tag . $ciphertext;
	}

	public function
	decrypt ($data, $user_key)
	{
		if ($user_key === '' || $user_key === null) {
			return false;
		}
		$header = self::IV_SIZE + self::SALT_SIZE + self::TAG_SIZE;

		if (strlen($data) < $header) {
			return false;
		}
		$iv   = substr($data, 0, self::IV_SIZE);
		$salt = substr($data, self::IV_SIZE, self::SALT_SIZE);
		$tag  = substr($data, self::IV_SIZE + self::SALT_SIZE, self::TAG_SIZE);
		$key  = $this->deriveKey($salt, $user_key);

		return openssl_decrypt(substr($data, $header), self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
	}

	public function
	get ($callable, $args, $auth, $uri, $timeout)
	{
		if (!extension_loaded('openssl') || !in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
			return call_user_func_array($callable, $args);
		}
		if (is_callable($callable, false, $callableName) === false) {
			return call_user_func_array($callable, $args);
		}
		$key = $this->key($auth, $callableName, $uri);

		// The key we use to encrypt the data:
		$user_key = $auth->pass . $callableName . $uri->uriFull();

		// First try to get the data from the cache:
		if ($this->read($key, $raw, $timeout)) {
			if ($this->unserialize($data, $raw, $user_key)) {
				return $data;
			}
		}
		// If that failed, run the function, store the result:
		$data = call_user_func_array($callable, $args);

		// Serialize the data, try to store it:
		if ($this->serialize($data, $raw, $user_key) !== false) {
			$this->write($key, $raw, $timeout);
		}
		return $data;
	}

	public function
	remove ($callable, $auth, $uri)
	{
		if (is_callable($callable, false, $callableName) === false) {
			return false;
		}
		return $this->delete($this->key($auth, $callableName, $uri));
	}
}
