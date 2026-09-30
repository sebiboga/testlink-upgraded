<?php
chdir('/home/runner/work/testlink-upgraded/testlink-upgraded');
require 'third_party/phpxmlrpc/lib/xmlrpc.inc';
$fail=0; $pass=0;
function chk($n,$c,$extra=''){ global $fail,$pass; if($c){$pass++; echo "PASS $n\n";} else {$fail++; echo "FAIL $n $extra\n";} }

// R2: scalar types through php_xmlrpc_encode
$cases = array(
  'int'      => array(42,             '<int>42</int>'),
  'string'   => array('hello',        '<string>hello</string>'),
  'double'   => array(1.5,            '<double>1.5</double>'),
  'bool-1'   => array(true,           '<boolean>1</boolean>'),
  'bool-0'   => array(false,          '<boolean>0</boolean>'),
  'null'     => array(null,           '<value></value>'),
);
foreach($cases as $n=>$c){
  $x = php_xmlrpc_encode($c[0]);
  chk("R2 $n", strpos($x->serialize(), $c[1]) !== false, 'got: '.$x->serialize());
}
// base64 / datetime
$b = new xmlrpcval('binary', $GLOBALS['xmlrpcBase64']);
chk('R2 base64', strpos($b->serialize(), '<base64>') !== false, $b->serialize());
$d = new xmlrpcval('20260930T12:00:00', $GLOBALS['xmlrpcDateTime']);
chk('R2 datetime', strpos($d->serialize(), '<dateTime.iso8601>20260930T12:00:00</dateTime.iso8601>') !== false, $d->serialize());

// R3: array + nested struct + serializeval()
$arr = php_xmlrpc_encode(array(1,2,3));
chk('R3 array', strpos($arr->serialize(), '<array>') !== false && strpos($arr->serialize(), '<data>') !== false, $arr->serialize());
$st = php_xmlrpc_encode(array('id'=>7,'summary'=>'crash on save','nested'=>array('k'=>'v')));
$s = $st->serialize();
chk('R3 struct', strpos($s,'<name>id</name>')!==false && strpos($s,'<name>summary</name>')!==false && strpos($s,'<name>nested</name>')!==false, $s);
$sv = $arr->serializeval($arr);
chk('R3 serializeval', $sv === $arr->serialize(), "diff:\n$sv\n".$arr->serialize());

// R7: legacy xmlrpcval API
$v = new xmlrpcval(9);
chk('R7 scalarval', $v->scalarval() == 9, var_export($v->scalarval(),true));
chk('R7 scalartyp', $v->scalartyp() === 'string', $v->scalartyp());
$i = new xmlrpcval(123, 'int');
chk('R7 int scalartyp', $i->scalartyp() === 'int', $i->scalartyp());
$stv = new xmlrpcval(array('a'=>1,'b'=>2,'c'=>3), 'struct');
chk('R7 structmemexists', $stv->structmemexists('b') === true);
chk('R7 structmem', $stv->structmem('a') === 1, var_export($stv->structmem('a'),true));
$stv->structreset();
$e1 = $stv->structeach();
chk('R7 structeach shape', is_array($e1) && $e1[0]==='a' && $e1[1]===1 && $e1['key']==='a' && $e1['value']===1 && array_keys($e1)===array(0,'value',1,'key'), json_encode($e1));
chk('R7 structeach 2nd', $stv->structeach()[0]==='b');
chk('R7 structeach 3rd', $stv->structeach()[0]==='c');
chk('R7 structeach end', $stv->structeach() === false);
$stv->structreset();
$stv->structeach(); $stv->structeach();
chk('R7 structeach resumes from pointer', $stv->structeach()[0]==='c');
$g = new xmlrpcval(array('x'=>new xmlrpcval('q')), 'struct');
chk('R7 getval', is_array($g->getval()) && $g->getval()['x']==='q', print_r($g->getval(),true));
// structeach on a non-struct val must not fatal
chk('R7 structeach non-struct', (new xmlrpcval(1))->structeach() === false);
// empty struct
$es = new xmlrpcval(array(), 'struct');
chk('R7 empty struct structeach', $es->structeach() === false);

// R6/R8: decode path - xmlrpcmsg parseResponse + php_xmlrpc_decode
$payload = '<?xml version="1.0"?>
<methodResponse><params><param><value><struct>
<member><name>id</name><value><int>7</int></value></member>
<member><name>summary</name><value><string>crash</string></value></member>
<member><name>tags</name><value><array><data>
<value><string>a</string></value><value><string>b</string></value>
</data></array></value></member>
</struct></value></param></params></methodResponse>';
$dec = php_xmlrpc_decode_xml($payload);
chk('R6 decode returns xmlrpcresp', $dec instanceof xmlrpcresp, is_object($dec)?get_class($dec):gettype($dec));
$out = php_xmlrpc_decode($dec->value());
chk('R6 decode struct', is_array($out) && $out['id']===7 && $out['summary']==='crash' && $out['tags']===array('a','b'), print_r($out,true));
$again = php_xmlrpc_encode($out);
chk('R8 re-encode stable', strpos($again->serialize(),'<int>7</int>')!==false && strpos($again->serialize(),'<name>tags</name>')!==false);
$fo = php_xmlrpc_decode_xml('<?xml version="1.0"?><methodResponse><fault><value><struct>
<member><name>faultCode</name><value><int>1</int></value></member>
<member><name>faultString</name><value><string>nope</string></value></member>
</struct></value></fault></methodResponse>');
chk('R6 fault decode', $fo instanceof xmlrpcresp && $fo->faultCode()==1 && $fo->faultString()==='nope', 'not a fault');
// extension_api datetime decode
$dt = php_xmlrpc_decode(new xmlrpcval('20260930T12:00:00', $GLOBALS['xmlrpcDateTime']), array('extension_api'));
chk('R6 datetime extension_api', is_object($dt) && $dt->xmlrpc_type==='datetime' && $dt->timestamp==strtotime('2026-09-30T12:00:00'), print_r($dt,true));
// php object encode/decode round trip
class TLU_Probe { public $id=3; public $name='probe'; }
$pe = php_xmlrpc_encode(new TLU_Probe(), array('encode_php_objs'));
chk('R8 php obj encode', strpos($pe->serialize(),'<name>id</name>')!==false, $pe->serialize());
$back = php_xmlrpc_decode($pe, array('decode_php_objs'));
chk('R8 php obj decode', is_object($back) && $back->id===3 && $back->name==='probe', print_r($back,true));

echo "\n== $pass passed, $fail failed ==\n";
exit($fail?1:0);
