<?php

namespace SambaDAV;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
	public static function
	fillPatternProvider ()
	{
		return
		[ [ 'foo\\bar@baz', '%w', 'foo' ]
		, [ 'foo\\bar@baz', '%u', 'bar' ]
		, [ 'foo\\bar@baz', '%d', 'baz' ]
		, [ 'foo\\bar',     '%u', 'bar' ]
		, [ 'foo\\bar',     '%d', '' ]
		, [ 'bar@',         '%w', '' ]
		, [ 'bar@',         '%u', 'bar' ]
		, [ 'bar@',         '%d', '' ]
		, [ 'foo\\bar',     '%d %u %w', false ]
		, [ '',             '%d %u %w', false ]
		, [ 'foo\\@bar',    '%u', '' ]
		, [ '\\f@',         '%u', 'f' ]
		, [ 'f@',           '%u', 'f' ]
		, [ '\\f',          '%u', 'f' ]
		] ;
	}

	#[DataProvider('fillPatternProvider')]
	public function
	testFillPattern ($user, $pattern, $expect)
	{
		$log = new Log\Filesystem(Log::NONE);

		$auth = new Auth(null, $log);
		$auth->user = $user;
		$this->assertEquals($expect, $auth->fillPattern($pattern));
	}

	public function
	testCheckSambaPatterns ()
	{
		$config = new Config();
		$config->samba_username_pattern = '%u';
		$config->samba_domain_pattern = null;

		$log = new Log\Filesystem(Log::NONE);

		$auth = new Auth($config, $log);
		$auth->user = 'joe';

		$auth->checkSambaPatterns();
		$this->assertEquals('joe', $auth->sambaUsername());
		$this->assertEquals(null, $auth->sambaDomain());
	}
}
