<?php
/**
 * This code is written as a proof-of-concept, written by Jurrian van Iersel <jurrian@vaniersel.net>,
 * to maintain RIPE-db objects based on assignments in NIPAP.
 * All sensitive information is removed from this file and comments are added on these places.
 *
 * This script requires PHP's XML- and CURL extensions and PEAR's XML_RCP2 library
 */

// Look if conif
if ( ! file_exists( './nipap-ripe-sync-config.php' ) ) {
    require_once './nipap-ripe-sync-config.php';
} else {
    die 'Config file does not exists. Did you remember to copy ./nipap-ripe-sync-config-sample.php to'+
        './nipap-ripe-sync-config.php and edit the parameters';
}

// This script requires XML_RPC2, http://pear.php.net/package/XML_RPC2
require_once 'XML/RPC2/Client.php';


// This is where the magic happens...
$client = XML_RPC2_Client::create('http://' . urlencode($nipapUsername) . ':' . urlencode($nipapPassword) . '@127.0.0.1:1337/XMLRPC');
$auth = array('authoritative_source' => 'nipap');

try {
	$objects = array();

	$result = $client->list_prefix(array(
		'auth' => $auth,
	));

	if (!is_array($result) || count($result)==0) {
		print 'Something went wrong with NIPAP';
		exit(1);
	}

	foreach ($result as $prefix) {
		if (4==$prefix['family'] && 256>$prefix['total_addresses']) {
			$firstIp = substr($prefix['prefix'], 0, strpos($prefix['prefix'], '/'));
			$lastIp = long2ip(ip2long($firstIp)+$prefix['total_addresses']-1);
			$inetnumKey = sprintf('%s - %s', $firstIp, $lastIp);
			if (array_key_exists('remarks', $prefix['avps'])) {
				$prefix['avps']['remarks'] = trim(preg_replace('/\s+/', ' ', $prefix['avps']['remarks']));
			}
			$objects[$inetnumKey] = new StdClass();
			$objects[$inetnumKey]->customer_id = $prefix['customer_id'];
			$objects[$inetnumKey]->attributes = array();
			$objects[$inetnumKey]->attributes = $prefix['avps'];
		}
	}

} catch(\Exception $e) {
	print $e . PHP_EOL;
	exit(1);
}

try {
	foreach ($updatePrefixes as $updatePrefix) {
		$url = sprintf('http://rest.db.ripe.net/search.xml?query-string=%s&type-filter=inetnum&flags=all-more&flags=no-irt&flags=no-filtering&flags=no-referenced', $updatePrefix);
		$xml = new \DOMDocument();
		if (!@$xml->load($url)) {
			print 'Invalid XML from RIPE-db for ' . $updatePrefix . PHP_EOL;
			continue;
		}
		$xml->formatOutput = true;
		$xpath = new \DOMXpath($xml);
		foreach ($xpath->query("/whois-resources/objects/object/primary-key/attribute[@name='inetnum']") as $ndInetnumKey) {
			$inetnumKey =  $ndInetnumKey->getAttribute('value');
			if (!array_key_exists($inetnumKey, $objects)) {
				// Delete object
				print 'Delete ' . $inetnumKey . PHP_EOL;
				$url = sprintf('https://rest.db.ripe.net/ripe/inetnum/%s?password=' . $ripeDbPassword, str_replace(' ', '%20', $inetnumKey));
				$ch = curl_init($url);
				if (false === $ch) {
				        throw new \Exception('Can not initialize curl with given url');
				}
				curl_setopt($ch, CURLOPT_FAILONERROR, true);
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
				$response = curl_exec($ch);
				curl_close($ch);
				if (false === $response) {
					print 'Failed deleting ' . $inetnumKey . PHP_EOL;
				}
			} else {
				// Compare existing objects
				$customerId = $objects[$inetnumKey]->customer_id;
				$attributes = $objects[$inetnumKey]->attributes;
				$checkedAttributes = array();
				foreach ($xpath->query('../../attributes/attribute', $ndInetnumKey) as $ndAttribute) {
					switch ($ndAttribute->getAttribute('name')) {
						case 'inetnum':
						case 'created':
						case 'last-modified':
						case 'status':
						case 'source':
						case 'notify':
							// value doesn't matter
							break;
						case 'netname':
							if (sprintf($netnameFormat, $customerId)!=$ndAttribute->getAttribute('value')) {
								print 'Difference in  ' . $ndAttribute->getAttribute('name') . ' of ' . $inetnumKey . PHP_EOL;
								continue 3;
							}
							break;
						case 'descr':
							if (sprintf('Dedicated space for customer %d', $customerId)!=$ndAttribute->getAttribute('value') 
							) {
								print 'Difference in  ' . $ndAttribute->getAttribute('name') . ' of ' . $inetnumKey . PHP_EOL;
								continue 3;
                            				}
							break;
						case 'admin-c':
							$allowedAdminC = $defaultAdminC;
							if (array_key_exists($customerId, $customerHandles)) {
								$allowedAdminC[] = $customerHandles[$customerId];
							}
							if (!in_array($ndAttribute->getAttribute('value'), $allowedAdminC)) {
								print 'Difference in  ' . $ndAttribute->getAttribute('name') . ' of ' . $inetnumKey . PHP_EOL;
								continue 3;
							}
							break;
						case 'tech-c':
							$allowedTechC = $defaultTechC;
							if (array_key_exists($customerId, $customerHandles)) {
								$allowedTechC[] = $customerHandles[$customerId];
							}
							if (!in_array($ndAttribute->getAttribute('value'), $allowedTechC)) {
								print 'Difference in  ' . $ndAttribute->getAttribute('name') . ' of ' . $inetnumKey . PHP_EOL;
								continue 3;
							}
							break;
						case 'country':
							if ($country!=$ndAttribute->getAttribute('value')) {
								print 'Difference in ' . $ndAttribute->getAttribute('name') . ' of ' . $inetnumKey . PHP_EOL;
								continue 3;
							}
							break;
						case 'remarks':
							if (!array_key_exists('remarks', $attributes) || $attributes['remarks']!=$ndAttribute->getAttribute('value')) {
								print 'Difference in ' . $ndAttribute->getAttribute('name') . ' of ' . $inetnumKey . PHP_EOL;
								continue 3;
							}
							break;
						case 'mnt-by':
						case 'mnt-routes':
							if ($maintainer!=$ndAttribute->getAttribute('value')) {
								print 'Difference in ' . $ndAttribute->getAttribute('name') . ' of ' . $inetnumKey . PHP_EOL;
								continue 3;
							}
							break;
						default:
							print 'unsupported attribute: ' . $ndAttribute->getAttribute('name') . PHP_EOL;
							continue 3;
					}
					$checkedAttributes[] = $ndAttribute->getAttribute('name');
				}
				if (!in_array('remarks', $checkedAttributes) && array_key_exists('remarks', $attributes)) {
					print 'Missing remarks: ' . $attributes['remarks'] . PHP_EOL;
					continue;
				}
				if (count($allowedAdminC) != count(array_keys($checkedAttributes, 'admin-c'))) {
					print 'Change in number of admin-c attributes' . PHP_EOL;
					continue;
				}
				if (count($allowedTechC) != count(array_keys($checkedAttributes, 'tech-c'))) {
					print 'Change in number of tech-c attributes' . PHP_EOL;
					continue;
				}
				unset($objects[$inetnumKey]);
			}
		}
	}
} catch(\Exception $e) {
	print $e . PHP_EOL;
}

// create or update objects
foreach ($objects as $inetnumKey => $obj) {
	$customerId = $obj->customer_id;
	$attributes = $obj->attributes;
	$xml = new DOMDocument('1.0', 'UTF-8');
	$xml->formatOutput = true;
	$ndWhoisResources = $xml->createElement('whois-resources');
	$xml->appendChild($ndWhoisResources);
	$ndObjects = $xml->createElement('objects');
	$ndWhoisResources->appendChild($ndObjects);
	$ndObject = $xml->createElement('object');
	$ndObject->setAttribute('type', 'inetnum');
	$ndObjects->appendChild($ndObject);
	$ndSource = $xml->createElement('source');
	$ndSource->setAttribute('id', 'ripe');
	$ndObject->appendChild($ndSource);
	$ndAttributes = $xml->createElement('attributes');
	$ndObject->appendChild($ndAttributes);
	// inetnum
	$ndAttribute = $xml->createElement('attribute');
	$ndAttribute->setAttribute('name', 'inetnum');
	$ndAttribute->setAttribute('value', $inetnumKey);
	$ndAttributes->appendChild($ndAttribute);
	// netname
	$ndAttribute = $xml->createElement('attribute');
	$ndAttribute->setAttribute('name', 'netname');
	$ndAttribute->setAttribute('value', sprintf($netnameFormat, $customerId));
	$ndAttributes->appendChild($ndAttribute);
	// descr
	$ndAttribute = $xml->createElement('attribute');
	$ndAttribute->setAttribute('name', 'descr');
	$ndAttribute->setAttribute('value', sprintf('Dedicated space for customer %d', $customerId));
	$ndAttributes->appendChild($ndAttribute);
	// country
	$ndAttribute = $xml->createElement('attribute');
	$ndAttribute->setAttribute('name', 'country');
	$ndAttribute->setAttribute('value', 'NL');
	$ndAttributes->appendChild($ndAttribute);
	// admin-c
	foreach ($allowedAdminC as $handle) {
		$ndAttribute = $xml->createElement('attribute');
		$ndAttribute->setAttribute('name', 'admin-c');
		$ndAttribute->setAttribute('value', $handle);
		$ndAttributes->appendChild($ndAttribute);
	}
	// tech-c
	foreach ($allowedTechC as $handle) {
		$ndAttribute = $xml->createElement('attribute');
		$ndAttribute->setAttribute('name', 'tech-c');
		$ndAttribute->setAttribute('value', $handle);
		$ndAttributes->appendChild($ndAttribute);
	}
	// remarks
	if (array_key_exists('remarks', $attributes)) {
		$ndAttribute = $xml->createElement('attribute');
		$ndAttribute->setAttribute('name', 'remarks');
		$ndAttribute->setAttribute('value', $attributes['remarks']);
		$ndAttributes->appendChild($ndAttribute);
	}
	// status
	$ndAttribute = $xml->createElement('attribute');
	$ndAttribute->setAttribute('name', 'status');
	$ndAttribute->setAttribute('value', 'ASSIGNED PA');
	$ndAttributes->appendChild($ndAttribute);
	// mnt-by
	$ndAttribute = $xml->createElement('attribute');
	$ndAttribute->setAttribute('name', 'mnt-by');
	$ndAttribute->setAttribute('value', $maintainer);
	$ndAttributes->appendChild($ndAttribute);
	// mnt-routes
	$ndAttribute = $xml->createElement('attribute');
	$ndAttribute->setAttribute('name', 'mnt-routes');
	$ndAttribute->setAttribute('value', $maintainer);
	$ndAttributes->appendChild($ndAttribute);
	// source
	$ndAttribute = $xml->createElement('attribute');
	$ndAttribute->setAttribute('name', 'source');
	$ndAttribute->setAttribute('value', 'RIPE');
	$ndAttributes->appendChild($ndAttribute);
	// notify
	if (!empty($notifyContact)) {
		$ndAttribute = $xml->createElement('attribute');
		$ndAttribute->setAttribute('name', 'notify');
		$ndAttribute->setAttribute('value', $notifyContact);
		$ndAttributes->appendChild($ndAttribute);
	}

	// XML ready
	$url = sprintf('https://rest.db.ripe.net/ripe/inetnum/%s?password=' . $ripeDbPassword, str_replace(' ', '%20', $inetnumKey));
	$ch = curl_init($url);
	if (false === $ch) {
	        throw new \Exception('Can not initialize curl with given url');
	}
	curl_setopt($ch, CURLOPT_FAILONERROR, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
	curl_setopt($ch, CURLOPT_POSTFIELDS, $xml->saveXml());
	$response = curl_exec($ch);
	curl_close($ch);
	if (false === $response) {
		print 'Failed updating ' . $inetnumKey . PHP_EOL;
	} else {
		print 'Updated ' . $inetnumKey . PHP_EOL;
	}
}
