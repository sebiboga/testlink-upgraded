<?php
chdir('/home/runner/work/testlink-upgraded/testlink-upgraded');
require 'third_party/phpxmlrpc/lib/xmlrpc.inc';
$client = new xmlrpc_client('http://127.0.0.1:8099/RPC2');
// exactly what tracxmlrpcInterface::sendCmd() does
$param = new xmlrpcval(intval(5));
$msg = new xmlrpcmsg('ticket.get');
$msg->addParam($param);
$response = $client->send($msg, 10);
echo "errno=", $response->errno, " errstr=", trim($response->errstr), "\n";
if (!$response->errno) {
  $v = $response->value();
  echo "kindOf=", $v->kindOf(), "\n";
  foreach (array('id','summary','priority') as $k) {
    echo "  $k = ", $v->structmem($k)->scalarval(), "\n";
  }
  echo "decoded=", trim(preg_replace('/\s+/', ' ', print_r(php_xmlrpc_decode($v), true))), "\n";
}
echo "cookie_header_seen=", isset($GLOBALS['_xh']['headers']['set-cookie']) ? 'yes':'no', "\n";
