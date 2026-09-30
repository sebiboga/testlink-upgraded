<?php
chdir('/home/runner/work/testlink-upgraded/testlink-upgraded');
require '/tmp/opencode/polyfill.php';
require $argv[1];
$out = array();
class Probe { public $id=3; public $name='probe'; }
$vals = array(
  'int'      => 42,
  'negint'   => -7,
  'string'   => 'he"llo <&> \'x\'',
  'utf8'     => "caf\xc3\xa9 \xe2\x82\xac",
  'empty'    => '',
  'double'   => 1.5,
  'zero'     => 0,
  'true'     => true,
  'false'    => false,
  'null'     => null,
  'list'     => array(1,'two',3.5),
  'assoc'    => array('a'=>1,'b'=>array('c'=>array('d'=>'deep'))),
  'obj'      => new Probe(),
);
foreach ($vals as $n=>$v) {
  $r = php_xmlrpc_encode($v);
  $out['enc:'.$n] = $r->serialize();
  $out['kind:'.$n] = $r->kindOf();
  $out['typ:'.$n]  = $r->scalartyp();
  $out['val:'.$n]  = var_export($r->scalarval(), true);
  $out['ser:'.$n]  = $r->serializeval($r);
}
foreach (array('base64','dateTime.iso8601','boolean','int','i4','double') as $t) {
  $v = new xmlrpcval('x', $t);
  $out['type:'.$t] = $v->serialize();
}
// struct API
$st = new xmlrpcval(array('a'=>1,'b'=>2,'c'=>3), 'struct');
$st->structreset();
while (list($k,$val) = $st->structeach()) { $out['structeach'][] = $k.'='.var_export($val,true); }
$out['structeach_end'] = var_export($st->structeach(), true);
$out['structmemexists'] = var_export($st->structmemexists('b'), true);
$st->structreset(); $st->structeach();
$out['structeach_resume'] = $st->structeach()[0];
try { $out['getval_rawstruct'] = print_r($st->getval(), true); }
catch (Throwable $e) { $out['getval_rawstruct'] = 'THROW: '.get_class($e).': '.$e->getMessage(); }
$gs = new xmlrpcval(array('x'=>new xmlrpcval('q'),'y'=>new xmlrpcval(2,'int')), 'struct');
$out['getval_objstruct'] = print_r($gs->getval(), true);
$go = new xmlrpcval(array('x'=>new xmlrpcval('q')), 'struct');
$out['getval_after'] = print_r($go->getval(), true);
$out['getval_twice'] = print_r($go->getval(), true);
$out['nonstruct_each'] = var_export($st->structeach(), true);
// msg round trip
$m = new xmlrpcmsg('ticket.get');
$m->addParam(new xmlrpcval(5));
$out['msg'] = $m->serialize();
$m2 = new xmlrpcmsg('ticket.create');
$m2->addParam(php_xmlrpc_encode(array('summary'=>'crash <&>', 'id'=>7, 'tags'=>array('a','b'))));
$out['msg2'] = $m2->serialize();
$d2 = php_xmlrpc_decode_xml($m2->serialize());
$out['dec2_kind'] = gettype($d2).'/'.(is_object($d2)?get_class($d2):'');
$out['dec2'] = print_r(php_xmlrpc_decode($d2->getParam(0)), true);
$out['dec2_method'] = isset($d2->method) ? $d2->method : 'n/a';
$out['dec2_numparams'] = $d2->getNumParams();
$f2 = php_xmlrpc_decode_xml('<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><int>1</int></value></member><member><name>faultString</name><value><string>no</string></value></member></struct></value></fault></methodResponse>');
$out['fault'] = (is_object($f2) && $f2 instanceof xmlrpcresp) ? 'code='.$f2->faultCode().' str='.$f2->faultString() : gettype($f2);
// dump() helper (deprecated, uses each() at 2830)
ob_start(); $st->dump(array('array'=>array(1,2,3), 'x'=>'y')); $out['dump'] = ob_get_clean();
// base64/datetime decode
$out['dt'] = print_r(php_xmlrpc_decode(new xmlrpcval('20260930T12:00:00', $GLOBALS['xmlrpcDateTime']), array('extension_api')), true);
$out['auto'] = php_xmlrpc_encode('20260930T12:00:00', array('auto_dates'))->serialize();
$out['dt_opt'] = print_r(php_xmlrpc_decode(new xmlrpcval('20260930T12:00:00', $GLOBALS['xmlrpcDateTime']), array('extension_api')), true);
$out['dt_xml'] = (new xmlrpcval('20260930T12:00:00', $GLOBALS['xmlrpcDateTime']))->serialize();
$out['obj_rt'] = print_r(php_xmlrpc_decode(php_xmlrpc_encode(new Probe(), array('encode_php_objs')), array('decode_php_objs')), true);
$out['obj_str'] = print_r(php_xmlrpc_decode(php_xmlrpc_encode(new Probe(), array('encode_php_objs'))), true);
echo serialize($out);
