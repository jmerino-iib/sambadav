<?php

namespace SambaDAV;

use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
	public function
	testSet ()
	{
		$config = new Config();
		$config->hallo = 'test';
		$this->assertEquals('test', $config->hallo);
	}

	public function
	testIssetPos ()
	{
		$config = new Config();
		$config->hallo = 'test';
		$this->assertTrue(isset($config->hallo));
	}

	public function
	testIssetNeg ()
	{
		$config = new Config();
		$this->assertFalse(isset($config->hallo));
	}
}
