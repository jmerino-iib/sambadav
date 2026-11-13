<?php

namespace SambaDAV;

use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
	private $testdata =
		"Lorem ipsum dolor sit amet, consectetur adipisicing elit,
		sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.\n";

	private $tempdir = null;

	// Counts how often cache->get() had to call through to the real function:
	public $calls = 0;

	protected function
	setUp (): void
	{
		$this->tempdir = sys_get_temp_dir() . '/sambadav-test-' . bin2hex(random_bytes(6));
	}

	protected function
	tearDown (): void
	{
		if ($this->tempdir !== null && is_dir($this->tempdir)) {
			foreach (glob($this->tempdir . '/*') as $file) {
				@unlink($file);
			}
			@rmdir($this->tempdir);
		}
	}

	public function
	testEncode ()
	{
		$cache = new Cache\NullCache();
		$enc = $cache->encrypt($this->testdata, 'abcd');
		$this->assertTrue(is_string($enc));
		$this->assertTrue($enc !== $this->testdata);
		$this->assertStringNotContainsString('Lorem ipsum', $enc);
	}

	public function
	testEncodeRoundtrip ()
	{
		$cache = new Cache\NullCache();
		$enc = $cache->encrypt($this->testdata, 'abcd');
		$dec = $cache->decrypt($enc, 'abcd');
		$this->assertSame($this->testdata, $dec);
	}

	public function
	testEncodeIsRandomized ()
	{
		// Same data and key must never yield the same ciphertext twice:
		$cache = new Cache\NullCache();
		$this->assertNotSame(
			$cache->encrypt($this->testdata, 'abcd'),
			$cache->encrypt($this->testdata, 'abcd')
		);
	}

	public function
	testDecryptWithWrongKey ()
	{
		$cache = new Cache\NullCache();
		$enc = $cache->encrypt($this->testdata, 'abcd');
		$this->assertFalse($cache->decrypt($enc, 'abce'));
	}

	public function
	testDecryptDetectsTampering ()
	{
		$cache = new Cache\NullCache();
		$enc = $cache->encrypt($this->testdata, 'abcd');

		// Flip one bit in the last byte (part of the ciphertext):
		$last = strlen($enc) - 1;
		$enc[$last] = chr(ord($enc[$last]) ^ 0x01);

		$this->assertFalse($cache->decrypt($enc, 'abcd'));
	}

	public function
	testDecryptTruncated ()
	{
		$cache = new Cache\NullCache();
		$this->assertFalse($cache->decrypt('too short', 'abcd'));
	}

	public function
	testEncryptRefusesEmptyKey ()
	{
		$cache = new Cache\NullCache();
		$this->assertFalse($cache->encrypt($this->testdata, ''));
		$this->assertFalse($cache->decrypt('x', ''));
	}

	public function
	testSerialize ()
	{
		$cache = new Cache\NullCache();
		$this->assertTrue($cache->serialize($this->testdata, $raw, 'abcd'));
		$this->assertTrue(is_string($raw));
	}

	public function
	testSerializeRoundtrip ()
	{
		$cache = new Cache\NullCache();
		$this->assertTrue($cache->serialize($this->testdata, $raw, 'abcd'));
		$this->assertTrue($cache->unserialize($data, $raw, 'abcd'));
		$this->assertEquals($data, $this->testdata);
	}

	public function
	testSerializeRoundtripArray ()
	{
		// This is the shape of what the cache really holds (an `ls` listing):
		$listing = [
			['name' => 'a.txt', 'flags' => 'A', 'size' => 12, 'mtime' => 1700000000],
			['name' => 'dir', 'flags' => 'D', 'size' => 0, 'mtime' => null],
		];
		$cache = new Cache\NullCache();
		$this->assertTrue($cache->serialize($listing, $raw, 'abcd'));
		$this->assertTrue($cache->unserialize($data, $raw, 'abcd'));
		$this->assertSame($listing, $data);
	}

	public function
	testUnserializeWrongKey ()
	{
		$cache = new Cache\NullCache();
		$this->assertTrue($cache->serialize($this->testdata, $raw, 'abcd'));
		$this->assertFalse($cache->unserialize($data, $raw, 'wxyz'));
	}

	public function
	testFilesystemWrite ()
	{
		$cache = new Cache\Filesystem($this->tempdir);
		$this->assertTrue($cache->write('foo', $this->testdata, 100));
	}

	public function
	testFilesystemRoundtrip ()
	{
		$cache = new Cache\Filesystem($this->tempdir);
		$this->assertTrue($cache->write('foo', $this->testdata, 100));
		$this->assertTrue($cache->read('foo', $data, 100));
		$this->assertEquals($data, $this->testdata);
	}

	public function
	testFilesystemDelete ()
	{
		$cache = new Cache\Filesystem($this->tempdir);
		$this->assertTrue($cache->write('foo', $this->testdata, 100));
		$this->assertTrue(is_null($cache->delete('foo')));
	}

	public function
	testFilesystemWriteRestoresUmask ()
	{
		$before = umask();
		$cache = new Cache\Filesystem($this->tempdir);
		$cache->write('foo', $this->testdata, 100);
		$this->assertSame($before, umask());
	}

	public function
	testFilesystemFilePermissions ()
	{
		$cache = new Cache\Filesystem($this->tempdir);
		$cache->write('foo', $this->testdata, 100);
		$this->assertSame(0600, fileperms($this->tempdir . '/foo') & 0777);
	}

	// Callable used by testGet*; must be public so cache->get() can call it:
	public function
	expensiveLookup ($arg)
	{
		$this->calls++;
		return ['result' => $arg];
	}

	public function
	testGetCachesResult ()
	{
		$log = new Log\Filesystem(Log::NONE);
		$auth = new Auth(null, $log);
		$auth->user = 'john';
		$auth->pass = 'secret';
		$uri = new URI('//server/share/dir');

		$cache = new Cache\Filesystem($this->tempdir);

		// First call runs the function, second is served from the cache:
		$first = $cache->get([$this, 'expensiveLookup'], ['x'], $auth, $uri, 100);
		$second = $cache->get([$this, 'expensiveLookup'], ['x'], $auth, $uri, 100);

		$this->assertSame(['result' => 'x'], $first);
		$this->assertSame(['result' => 'x'], $second);
		$this->assertSame(1, $this->calls);
	}

	public function
	testGetIsPerUser ()
	{
		$log = new Log\Filesystem(Log::NONE);
		$uri = new URI('//server/share/dir');
		$cache = new Cache\Filesystem($this->tempdir);

		$john = new Auth(null, $log);
		$john->user = 'john';
		$john->pass = 'secret';

		$jane = new Auth(null, $log);
		$jane->user = 'jane';
		$jane->pass = 'other';

		$cache->get([$this, 'expensiveLookup'], ['x'], $john, $uri, 100);
		$cache->get([$this, 'expensiveLookup'], ['x'], $jane, $uri, 100);

		// One user's cached listing must never be served to another user:
		$this->assertSame(2, $this->calls);
	}

	public function
	testGetAfterRemove ()
	{
		$log = new Log\Filesystem(Log::NONE);
		$auth = new Auth(null, $log);
		$auth->user = 'john';
		$auth->pass = 'secret';
		$uri = new URI('//server/share/dir');

		$cache = new Cache\Filesystem($this->tempdir);

		$cache->get([$this, 'expensiveLookup'], ['x'], $auth, $uri, 100);
		$cache->remove([$this, 'expensiveLookup'], $auth, $uri);
		$cache->get([$this, 'expensiveLookup'], ['x'], $auth, $uri, 100);

		$this->assertSame(2, $this->calls);
	}

	public function
	testNullCacheNeverCaches ()
	{
		$log = new Log\Filesystem(Log::NONE);
		$auth = new Auth(null, $log);
		$auth->user = 'john';
		$auth->pass = 'secret';
		$uri = new URI('//server/share/dir');

		$cache = new Cache\NullCache();

		$cache->get([$this, 'expensiveLookup'], ['x'], $auth, $uri, 100);
		$cache->get([$this, 'expensiveLookup'], ['x'], $auth, $uri, 100);

		$this->assertSame(2, $this->calls);
	}
}
