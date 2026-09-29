// Focused executable browser-handler regression; no browser storage or DOM package.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const app = fs.readFileSync(require('node:path').join(__dirname, '../../public_html/assets/js/app.js'), 'utf8');
const sourceCode = app.slice(app.indexOf("    document.querySelectorAll('form[data-bp-code-submit]')"), app.indexOf("    document.querySelectorAll('[data-bp-reorder-list]')"));
const source = {value: 'café source', disabled: false};
const status = {textContent: ''};
let submit, download, payload, navigated, clicked = false;
const form = {action:'/admin/theme-templates/save', querySelector: s => s.includes('textarea') ? source : {addEventListener: (event, fn) => { download = fn; }}, addEventListener: (event, fn) => { submit = fn; }};
const document = {querySelectorAll: () => [form], querySelector: () => status, createElement: () => ({click: () => { clicked = true; }})};
const window = {TextEncoder, fetch: true, btoa: s => Buffer.from(s, 'binary').toString('base64'), location: {assign: path => { navigated = path; }}};
let reply;
const headers = reference => ({get: name => name === 'Content-Type' ? 'application/json; charset=utf-8' : reference});
const sandbox = {document, window, TextEncoder, Blob, URL: {createObjectURL: () => 'blob:local', revokeObjectURL: () => {}}, setTimeout: fn => fn(),
    FormData: class {constructor() {this.data = {source:source.value};} set(k,v) {this.data[k]=v;} delete(k) {delete this.data[k];}},
    fetch: async (url, options) => {payload=options; return reply();}};
vm.runInNewContext(sourceCode, sandbox);
const event = () => ({submitter:{hasAttribute:()=>false}, prevented:false, preventDefault(){this.prevented=true;}});
(async () => {
    reply = () => ({ok:false, status:404, headers:{get:name=> name === 'Content-Type' ? 'text/html' : 'reference-404'}, json:async()=> {throw new SyntaxError('HTML response');}});
    await submit(event());
    assert.equal(source.value,'café source'); assert.equal(source.disabled,false);
    assert.match(status.textContent,/reference-404/); assert.equal(navigated,undefined);
    assert.match(status.textContent,/HTTP 404/);
    assert.equal(payload.redirect, 'error');
    reply = () => ({ok:true, redirected:true, headers:headers('redirect'), json:async()=>({ok:true,location:'/wrong'})});
    await submit(event()); assert.match(status.textContent,/redirected/); assert.equal(navigated,undefined);
    reply = () => ({ok:false, status:405, headers:headers('method-405'), json:async()=>({ok:false,message:'Template saving requires POST.'})});
    await submit(event()); assert.match(status.textContent,/requires POST/); assert.equal(navigated,undefined);
    reply = () => {throw new Error('Network disconnected');};
    await submit(event()); assert.match(status.textContent,/Network disconnected/);
    download(); assert.equal(clicked,true);
    reply = () => ({ok:true, headers:headers('success'), json:async()=>({ok:true,location:'/editor'})});
    await submit(event()); assert.equal(navigated,'/editor');
    assert.equal(Buffer.from(payload.body.data.source_encoded,'base64').toString(),'café source');
    assert.equal(payload.body.data.source,undefined);
    navigated=undefined;
    reply = () => {source.value='newer edits'; return {ok:true,headers:headers(''),json:async()=>({ok:true,location:'/editor'})};};
    await submit(event()); assert.equal(navigated,undefined); assert.match(status.textContent,/newer unsaved edits/);
    window.TextEncoder = undefined;
    const native = event(); await submit(native); assert.equal(native.prevented,false); assert.equal(source.disabled,false);
    console.log('Template save JavaScript recovery checks passed');
})().catch(error => {console.error(error); process.exitCode=1;});
