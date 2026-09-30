<?php
// minimal live XML-RPC endpoint: answers ticket.get with a struct
$srv = stream_socket_server('tcp://127.0.0.1:8099', $e, $es);
if(!$srv){ fwrite(STDERR, "listen failed: $es\n"); exit(1); }
$conn = stream_socket_accept($srv, 30);
if(!$conn){ fwrite(STDERR, "no connection\n"); exit(1); }
stream_set_timeout($conn, 5);
$body = '';
while (($line = fgets($conn)) !== false) {
  $body .= $line;
  if (rtrim($line, "\r\n") === '') { break; }
}
$len = 0;
if (preg_match('/Content-Length:\s*(\d+)/i', $body, $m)) { $len = (int)$m[1]; $body .= stream_get_contents($conn, $len); }
$req = (strpos($body, 'ticket.get') !== false);
$payload = $req
  ? '<?xml version="1.0"?><methodResponse><params><param><value><struct>'
    .'<member><name>id</name><value><int>5</int></value></member>'
    .'<member><name>summary</name><value><string>trac bug 5</string></value></member>'
    .'<member><name>priority</name><value><string>normal</string></value></member>'
    .'</struct></value></param></params></methodResponse>'
  : '<?xml version="1.0"?><methodResponse><fault><value><struct>'
    .'<member><name>faultCode</name><value><int>1</int></value></member>'
    .'<member><name>faultString</name><value><string>unknown method</string></value></member>'
    .'</struct></value></fault></methodResponse>';
$hdr = "HTTP/1.1 200 OK\r\n"
     . "Content-Type: text/xml\r\n"
     . "Content-Length: ".strlen($payload)."\r\n"
     . "Set-Cookie: tl_session=xyz; path=/\r\n"
     . "Server: TL-fixture/1.0\r\n"
     . "Connection: close\r\n\r\n";
fwrite($conn, $hdr.$payload);
fclose($conn);
fclose($srv);
file_put_contents('/tmp/opencode/live_request.txt', $body);
file_put_contents('/tmp/opencode/live_wire.txt', $hdr.$payload);
