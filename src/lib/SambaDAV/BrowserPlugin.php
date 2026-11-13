<?php	// $Format:SambaDAV: commit %h @ %cd$

# Copyright (C) 2014  Bokxing IT, http://www.bokxing-it.nl
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

use Sabre\DAV;
use Sabre\HTTP;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class BrowserPlugin extends DAV\Browser\Plugin
{
	private $config;
	
	public function
	__construct ($config)
	{
		parent::__construct();
		$this->config = $config;
	}

	public function 
	initialize(DAV\Server $server) 
	{
		parent::initialize($server);
		$this->server = $server;
		if ($this->enablePost) $this->server->on('method:POST', [$this,'httpPOSTExtra']);
	}


    	/**
    	 * Handles POST requests for tree operations not handled in the SabreDAV parent clas
    	 *
    	 * @param RequestInterface $request
    	 * @param ResponseInterface $response
    	 * @return bool
    	 */
	public function 
	httpPOSTExtra(RequestInterface $request, ResponseInterface $response) 
	{

		$contentType = (string)$request->getHeader('Content-Type');
		[$contentType] = explode(';', $contentType);
		if ($contentType !== 'application/x-www-form-urlencoded' &&
		$contentType !== 'multipart/form-data') {
			return;
		}

		$postVars = $request->getPostData();
		
		if (!isset($postVars['sabreActionExtra']))
			return;

		$uri = $request->getPath();
		
		switch($postVars['sabreActionExtra']) {
			case 'del':
				if (isset($postVars['path'])) {
					// Using basename() because we won't allow slashes
					[, $Name] = \Sabre\Uri\split(trim($postVars['path']));
					if(!empty($Name) && $this->config->browserplugin_enable_delete === true) {
						$this->server->tree->delete($uri . '/' . $Name);
					}
				}
				break;
		}

		$response->setHeader('Location', $request->getUrl());
		$response->setStatus(302);
		return false;
	}

	public function
	generateDirectoryIndex ($path)
	{
		$version = (DAV\Server::$exposeVersion)
			? DAV\Version::VERSION
			: '';

		$node = $this->server->tree->getNodeForPath($path);

		$html = <<<HTML
<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8">
    <title>Index for {$this->escapeHTML($path)}/ - SambaDAV</title>
    <link rel="stylesheet" href="{$this->server->getBaseUri()}style.css"/>
    <link rel="shortcut icon" href="{$this->server->getBaseUri()}favicon.ico" type="image/vnd.microsoft.icon"/>
  </head>
  <body>

HTML;
		if ($this->config->anonymous_only === false) {
			$html .= "
    <p id=\"logout\"><a href=\"?logout\">switch user (logout)</a></p>";
		}

		$html .= <<<HTML
    <h1>{$this->escapeHTML($node->uri->uriFull())}</h1>
    <table id="actions">
      <tbody>

HTML;
		$output = '';
		if ($this->enablePost) {
			$this->server->emit('onHTMLActionsPanel', [$node, &$output, $path]);
		}
		if ($output) {
			$html .= $output;
		}
		$html .= <<<HTML
      </tbody>
    </table>
    <table>
      <colgroup>
        <col width="15px"/>
        <col/>
        <col/>
        <col/>
        <col/>
      </colgroup>
      <thead>
        <tr>
          <th></th>
          <th>Name</th>
          <th>Type</th>
          <th>Size</th>
          <th>Last modified</th>
HTML;
	  if ($this->config->browserplugin_enable_delete === true) { $html .= "<th>Delete</th>"; }
	  $html .= <<<HTML
        </tr>
      </thead>
      <tbody>

HTML;

		// If path is empty, there is no parent:
		if ($path) {
			[$parentUri] = \Sabre\Uri\split($path);
			$fullPath = HTTP\encodePath($this->server->getBaseUri() . $parentUri);
			$html .= <<<HTML
        <tr class="dir">
          <td><a href="$fullPath"><img src="{$this->server->getBaseUri()}dir.png" alt="Parent"/></a></td>
          <td><a href="$fullPath">..</a></td>
          <td>[parent]</td>
          <td></td>
          <td></td>
HTML;
	  if ($this->config->browserplugin_enable_delete === true) { $html .= "<td></td>"; }
	  $html .= <<<HTML
        </tr>

HTML;
		}
		if ($node instanceof DAV\ICollection) {
			$subNodes = $this->server->getPropertiesForChildren($path, [
				'{DAV:}displayname',
				'{DAV:}resourcetype',
				'{DAV:}getcontenttype',
				'{DAV:}getcontentlength',
				'{DAV:}getlastmodified',
			]);
			foreach ($subNodes as $subPath => $subProps)
			{
				$subNode = $this->server->tree->getNodeForPath($subPath);
				$fullPath = HTTP\encodePath($this->server->getBaseUri() . $subPath);
				[, $displayPath] = \Sabre\Uri\split($subPath);

				$subNodes[$subPath]['subNode'] = $subNode;
				$subNodes[$subPath]['fullPath'] = $fullPath;
				$subNodes[$subPath]['displayPath'] = $displayPath;
			}
			uasort($subNodes, [$this, 'compareNodes']);

			foreach ($subNodes as $subProps)
			{
				$size = (isset($subProps['{DAV:}getcontentlength']))
					? $subProps['{DAV:}getcontentlength']
					: '';

				$lastmodified = (isset($subProps['{DAV:}getlastmodified']))
					? $subProps['{DAV:}getlastmodified']->getTime()->format('F j, Y, H:i:s')
					: '';

				$fullPath_decoded = HTTP\decodePath($subProps['fullPath']);
				$fullPath = $this->escapeHTML($subProps['fullPath']);

				if (isset($subProps['{DAV:}resourcetype']) && in_array('{DAV:}collection', $subProps['{DAV:}resourcetype']->getValue())) {
					$trclass = 'class="dir"';
					$icon = 'dir.png';
					$type = 'Directory';
				}
				else {
					$trclass = 'class="file"';
					$icon = 'file.png';
					$type = (isset($subProps['{DAV:}getcontenttype']))
						? $subProps['{DAV:}getcontenttype']
						: 'Unknown';
				}
				$html .= "        <tr $trclass>\n";
				$html .= "          <td><a href=\"$fullPath\"><img src=\"{$this->server->getBaseUri()}$icon\" alt=\"\"/></a></td>\n";
				$html .= "          <td><a href=\"$fullPath\">{$this->escapeHTML($subProps['displayPath'])}</a></td>\n";
				$html .= "          <td>{$this->escapeHTML($type)}</td>\n";
				$html .= "          <td>$size</td>\n";
				$html .= "          <td>$lastmodified</td>\n";

				if ($this->config->browserplugin_enable_delete === true) { 
					$html .= "          <td>\n";
					// The name ends up in a JavaScript string inside an HTML attribute:
					$confirm = $this->escapeHTML(json_encode(
						'Are you sure you want to delete ' . $subProps['displayPath'],
						JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
					));
					$html .= "          <form method=\"post\" enctype=\"multipart/form-data\" onsubmit=\"return confirm($confirm);\">\n";
					$html .= "          <input name=\"sabreActionExtra\" value=\"del\" type=\"hidden\">\n";
					$html .= "          <input name=\"path\" value=\"{$this->escapeHTML($fullPath_decoded)}\" type=\"hidden\">\n";
					$html .= "          <input value=\"Delete\" type=\"submit\">\n";
					$html .= "          </form>\n";
					$html .= "          </td>\n";
				}
				$html .= "        </tr>\n";
			}
		}
		$html .= <<<HTML
      </tbody>
    </table>
    <img src="{$this->server->getBaseUri()}logo-sambadav.png" id="sambadav-logo"/><address>Generated by SabreDAV $version</address>
  </body>
</html>
HTML;

		$this->server->httpResponse->setHeader('Content-Security-Policy', "img-src 'self'; style-src 'self';");

		return $html;
	}

	public function
	htmlActionsPanel (DAV\INode $node, &$output, $path)
	{
		if (!$node instanceof DAV\ICollection) {
			return;
		}

		$output .= <<<HTML
<tr><form method="post"><input name="sabreAction" value="mkcol" type="hidden">
  <td><h3>New folder</h3></td>
  <td><label for="name">Name:</label></td>
  <td colspan="3"><input name="name" type="text"></td>
  <td><input value="create" type="submit"></td>
  </form>
</tr>
<tr><form method="post" enctype="multipart/form-data"><input name="sabreAction" value="put" type="hidden">
  <td><h3>Upload file</h3></td>
  <td><label for="file">File:</label></td>
  <td><input name="file" type="file"></td>
  <td><label for="name">Name (optional):</label></td>
  <td><input name="name" type="text"></td>
  <td><input value="upload" type="submit"></td>
</form></tr>
HTML;

	}

}
