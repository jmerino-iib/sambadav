<?php

namespace SambaDAV;

use PHPUnit\Framework\TestCase;

class SMBParserTest extends TestCase
{
	public function
	testConnectFailed ()
	{
		$outp = "Connection to server failed (Error NT_STATUS_UNSUCCESSFUL)\n";
		$fd = fopen('data://text/plain,' . $outp, 'r');
		$log = new Log\Filesystem(Log::NONE);

		$proc = $this->getMockBuilder('\SambaDAV\SMBClient\Process')->onlyMethods(['getStdoutHandle'])->setConstructorArgs([null, null, $log])->getMock();

		$proc->method('getStdoutHandle')
		     ->willReturn($fd);

		$parser = new SMBClient\Parser($proc);
		$this->assertEquals(SMB::STATUS_UNAUTHENTICATED, $parser->getShares());
	}

	public function
	testGetShares ()
	{
		// Actual output from `smbclient -gNL //server`:
		$outp = <<<EOT
Anonymous login successful
Printer|pdf|PDF printer
Disk|print$|Printer Drivers
IPC|IPC$|IPC Service (fixeer)
Disk|webshare|
Disk|recordings|Recorded data
Disk|osx_server|test
Disk|iso|Recovery CD and DVDs
Disk|intranet|
Disk|ftp|
Printer|dell1720|Dell Laser Printer 1720dn
Printer|Xerox6128|Xerox6128
Disk|acltest|
Anonymous login successful
Server|JUPITER|jupiter
Server|W81VM|
Workgroup|TESTING|TESTINGPC09
Workgroup|DEMO|DEMO-SERVER
Workgroup|S4|SAMBA4
Workgroup|WORKGROUP|RICHARD
EOT;
		$fd = fopen('data://text/plain,' . $outp, 'r');
		$log = new Log\Filesystem(Log::NONE);

		$proc = $this->getMockBuilder('\SambaDAV\SMBClient\Process')->onlyMethods(['getStdoutHandle'])->setConstructorArgs([null, null, $log])->getMock();

		$proc->method('getStdoutHandle')
		     ->willReturn($fd);

		$parser = new SMBClient\Parser($proc);
		$this->assertEquals(array
			( 'webshare'
			, 'recordings'
			, 'osx_server'
			, 'iso'
			, 'intranet'
			, 'ftp'
			, 'acltest'
			) , $parser->getShares());
	}

	public function
	testGetDiskUsage ()
	{
		// Actual output from `smbclient [options] -c du`:
		$outp = <<<EOT

		50000 blocks of size 2097152. 48993 blocks available
Total number of bytes: 108982041
EOT;
		$fd = fopen('data://text/plain,' . $outp, 'r');
		$log = new Log\Filesystem(Log::NONE);

		$proc = $this->getMockBuilder('\SambaDAV\SMBClient\Process')->onlyMethods(['getStdoutHandle'])->setConstructorArgs([null, null, $log])->getMock();

		$proc->method('getStdoutHandle')
		     ->willReturn($fd);

		$parser = new SMBClient\Parser($proc);
		$this->assertEquals(array
			( 2097152 * (50000 - 48993)	// Used space (bytes)
			, 2097152 * 48993		// Free space (bytes)
			) , $parser->getDiskUsage());
	}

	public function
	testGetDiskUsageFail ()
	{
		// Actual output from `smbclient [options] -c du` with wrong pass:
		$outp = "session setup failed: NT_STATUS_LOGON_FAILURE\n";

		$fd = fopen('data://text/plain,' . $outp, 'r');
		$log = new Log\Filesystem(Log::NONE);

		$proc = $this->getMockBuilder('\SambaDAV\SMBClient\Process')->onlyMethods(['getStdoutHandle'])->setConstructorArgs([null, null, $log])->getMock();

		$proc->method('getStdoutHandle')
		     ->willReturn($fd);

		$parser = new SMBClient\Parser($proc);
		$this->assertEquals(SMB::STATUS_UNAUTHENTICATED, $parser->getDiskUsage());
	}

	private function
	parserFor ($outp)
	{
		$fd = fopen('php://memory', 'r+');
		fwrite($fd, $outp);
		rewind($fd);

		$log = new Log\Filesystem(Log::NONE);
		$proc = $this->getMockBuilder('\SambaDAV\SMBClient\Process')->onlyMethods(['getStdoutHandle'])->setConstructorArgs([null, null, $log])->getMock();
		$proc->method('getStdoutHandle')->willReturn($fd);

		return new SMBClient\Parser($proc);
	}

	private function
	lsLine ($name, $flags, $size, $time)
	{
		// Same printf format that smbclient uses, see Parser::parseFileLine():
		return sprintf("  %-30s%7.7s %8.0f  %s\n", $name, $flags, $size, $time);
	}

	public function
	testGetListing ()
	{
		$outp = $this->lsLine('file.txt', 'A', 12, 'Mon Mar  8 13:00:07 2010')
		      . $this->lsLine('a dir', 'D', 0, 'Tue Mar  9 08:30:00 2010');

		$this->assertEquals(
			[ [ 'name' => 'file.txt', 'flags' => 'A', 'size' => 12, 'mtime' => mktime(13, 0, 7, 3, 8, 2010) ]
			, [ 'name' => 'a dir',    'flags' => 'D', 'size' => 0,  'mtime' => mktime(8, 30, 0, 3, 9, 2010) ]
			], $this->parserFor($outp)->getListing());
	}

	public function
	testGetListingSkipsOkStatusLine ()
	{
		// An NT_STATUS_OK on one of the first two lines is acceptable;
		// parsing must carry on with the next line:
		$outp = "NT_STATUS_OK\n" . $this->lsLine('file.txt', 'A', 12, 'Mon Mar  8 13:00:07 2010');

		$listing = $this->parserFor($outp)->getListing();
		$this->assertCount(1, $listing);
		$this->assertEquals('file.txt', $listing[0]['name']);
	}

	public function
	testGetListingUnparseableDate ()
	{
		$listing = $this->parserFor($this->lsLine('file.txt', 'A', 12, 'not a date'))->getListing();

		$this->assertCount(1, $listing);
		$this->assertNull($listing[0]['mtime']);
	}

	public function
	testGetListingNotFound ()
	{
		$outp = "NT_STATUS_OBJECT_PATH_NOT_FOUND listing \\nonexistent\\\n";
		$this->assertEquals(SMB::STATUS_NOTFOUND, $this->parserFor($outp)->getListing());
	}
}
