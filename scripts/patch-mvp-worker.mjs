const MISSING_GLUE = [
  ['_setThrew', 'setThrew'],
  ['___cxa_can_catch', '__cxa_can_catch'],
  ['___cxa_decrement_exception_refcount', '__cxa_decrement_exception_refcount'],
  ['___cxa_demangle', '__cxa_demangle'],
  ['___cxa_get_exception_ptr', '__cxa_get_exception_ptr'],
  ['___cxa_increment_exception_refcount', '__cxa_increment_exception_refcount'],
];

const DECLARE_ANCHOR = 'wasmExports=createWasm()';
const READY_ANCHOR = 'removeRunDependency("wasm-instantiate"),wasmExports}';

// duckdb-wasm 1.32.0's MVP worker calls these names, but the matching wasm
// binary exports them under a different spelling and the glue never assigns
// them. A DuckDB exception then dies as "ReferenceError: _setThrew is not defined"
// instead of the real error. Declare the names, then point them at the wasm
// exports once instantiation has filled wasmExports.
export function patchMvpWorker(source) {
  if (count(source, DECLARE_ANCHOR) !== 1 || count(source, READY_ANCHOR) !== 1) {
    throw new Error('DuckDB MVP worker glue did not match the 1.32.0 patch anchors.');
  }
  const declared = `${DECLARE_ANCHOR},${MISSING_GLUE.map(([jsName]) => jsName).join(',')}`;
  const assigned = `removeRunDependency("wasm-instantiate"),${MISSING_GLUE.map(([jsName, exportName]) => `${jsName}=wasmExports.${exportName}`).join(',')},wasmExports}`;
  return source.replace(DECLARE_ANCHOR, declared).replace(READY_ANCHOR, assigned);
}

function count(source, needle) {
  let found = 0;
  let index = 0;
  while (index >= 0) {
    index = source.indexOf(needle, index);
    if (index < 0) {
      break;
    }
    found += 1;
    index += needle.length;
  }
  return found;
}
