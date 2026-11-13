<?php

namespace SambaDAV;

use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
	public function
	testGetName ()
	{
		$file = new File(null, null, null, null, new URI('//server/share/dir/file.txt'), null, null, null, null);
		$this->assertEquals('file.txt', $file->getName());
	}

	public function
	testSetName ()
	{
		$uri = new URI('//server/share/dir/file.txt');
		$log = new Log\Filesystem(Log::NONE);

		// Mock SMB to assert that $smb->rename is called with proper arguments:
		$smb = $this->getMockBuilder('\SambaDAV\SMB')->onlyMethods(['rename'])->setConstructorArgs([null, null, $log])->getMock();
		$smb->expects($this->once())
		    ->method('rename')
		    ->with($uri, $this->equalTo('new.pdf'));

		$file = new File(null, null, $log, $smb, $uri, null, null, null, null);

		$this->assertTrue($file->setName('new.pdf'));

		$this->assertEquals('new.pdf', $uri->name());
	}

	public function
	testPut ()
	{
		$uri = new URI('//server/share/dir/file.txt');
		$log = new Log\Filesystem(Log::NONE);

		$data = 'Hello, brave new world.';

		$smb = $this->getMockBuilder('\SambaDAV\SMB')->onlyMethods(['put'])->setConstructorArgs([null, null, $log])->getMock();
		$smb->expects($this->once())
		    ->method('put')
		    ->with($uri, $data)
		    ->willReturn(SMB::STATUS_OK);

		$file = new File(null, null, $log, $smb, $uri, null, null, null, null);

		$this->assertEquals(null, $file->put($data));
	}
}
