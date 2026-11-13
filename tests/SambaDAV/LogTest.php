<?php

namespace SambaDAV;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LogTest extends TestCase
{
	public function
	testError ()
	{
		$log = $this->getMockBuilder('\SambaDAV\Log\Filesystem')->onlyMethods(['commit'])->getMock();
		$log->expects($this->once())
		    ->method('commit')
		    ->with($this->equalTo(Log::ERROR), $this->stringContains(': error: NOOO'));

		$log->error("NOOO");
	}

	public function
	testThresholdDenied ()
	{
		// Log message is below threshold; expect no call:
		$log = $this->getMockBuilder('\SambaDAV\Log\Filesystem')->onlyMethods(['commit'])->getMock();
		$log->expects($this->exactly(0))
		    ->method('commit');

		$log->debug("NOOO");
	}

	public function
	testThresholdAllowed ()
	{
		// Log message is above threshold; expect call:
		$log = $this->getMockBuilder('\SambaDAV\Log\Filesystem')->onlyMethods(['commit'])->setConstructorArgs([Log::DEBUG])->getMock();
		$log->expects($this->once())
		    ->method('commit')
		    ->with($this->equalTo(Log::DEBUG), $this->stringContains(': debug: Hello world'));

		$log->debug('Hello world');
	}

	public function
	testStrayPercentIsNotFatal ()
	{
		// A filename like '100% sure %s.txt' ends up in log messages; PHP 8
		// throws on a sprintf() format with more placeholders than arguments:
		$log = $this->getMockBuilder('\SambaDAV\Log\Filesystem')->onlyMethods(['commit'])->getMock();
		$log->expects($this->once())
		    ->method('commit')
		    ->with($this->equalTo(Log::ERROR), $this->stringContains('100% sure %s.txt'));

		$log->error('100% sure %s.txt');
	}

	public function
	testFormatArguments ()
	{
		$log = $this->getMockBuilder('\SambaDAV\Log\Filesystem')->onlyMethods(['commit'])->getMock();
		$log->expects($this->once())
		    ->method('commit')
		    ->with($this->equalTo(Log::ERROR), $this->stringContains(": error: file '100% sure.txt' failed"));

		$log->error("file '%s' failed", '100% sure.txt');
	}

	public static function
	setLevelProvider ()
	{
		return
		[ [ null,    Log::WARN  ]	// Default
		, [ false,   Log::WARN  ] 	// Default
		, [ 'xxxxx', Log::WARN  ] 	// Default
		, [ 'none',  Log::NONE  ]
		, [ 'error', Log::ERROR ]
		, [ 'warn',  Log::WARN  ]
		, [ 'info',  Log::INFO  ]
		, [ 'debug', Log::DEBUG ]
		, [ 'trace', Log::TRACE ]
		, [ 'none',  Log::NONE  ]
		] ;
	}

	#[DataProvider('setLevelProvider')]
	public function
	testSetLevel ($value, $expect)
	{
		// Create log with default value:
		$log = new Log\Filesystem(Log::WARN);
		$log->setLevel($value);
		$this->assertEquals($expect, $log->level);
	}
}
