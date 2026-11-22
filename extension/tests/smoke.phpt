--TEST--
The module loads, reports its version, and a Vm answers an invocation
--EXTENSIONS--
restate
--FILE--
<?php
var_dump(Restate\Extension\version() === phpversion('restate'));
var_dump(Restate\Extension\contract_version() >= 1);
$vm = new Restate\Extension\Vm([['content-type', 'application/vnd.restate.invocation.v5']]);
var_dump($vm->responseHead()['status_code']);
?>
--EXPECT--
bool(true)
bool(true)
int(200)
