// Optional real HTTP/official SDK acceptance. No model calls or installation data.
// Usage: node radpress/tests/mcp_http.mjs /absolute/path/to/temporary/sdk-prefix [absolute-claude-binary]
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtemp, readFile, rm, chmod } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { createServer } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';

const sdkPrefix = process.argv[2];
const claudeBinary = process.argv[3];
assert(sdkPrefix && sdkPrefix === resolve(sdkPrefix), 'Pass an absolute, separately installed SDK prefix.');
assert(!claudeBinary || claudeBinary === resolve(claudeBinary), 'Optional Claude Code binary must be an absolute path.');
const sdkRequire = createRequire(join(sdkPrefix, 'package.json'));
const { Client, StreamableHTTPClientTransport } = sdkRequire('@modelcontextprotocol/client');
const { Ajv } = sdkRequire('@modelcontextprotocol/client/validators/ajv');
const sdkPackage = JSON.parse(await readFile(join(sdkPrefix, 'node_modules/@modelcontextprotocol/client/package.json'), 'utf8'));
const php = process.env.PRESS_TEST_PHP || 'php';
const router = join(dirname(fileURLToPath(import.meta.url)), 'fixtures/mcp_http.php');
const secrets = [];
const checks = [];

async function freePort() {
  const socket = createServer();
  await new Promise((resolve, reject) => { socket.once('error', reject); socket.listen(0, '127.0.0.1', resolve); });
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  return port;
}

async function check(prefix) {
  const root = await mkdtemp(join(tmpdir(), 'press-mcp-http-'));
  await chmod(root, 0o700);
  const clients = [];
  let server;
  let serverLog = '';
  try {
    const port = await freePort();
    const base = `http://127.0.0.1:${port}${prefix}`;
    const credentials = JSON.parse(execFileSync(php, [router, 'setup', root, base], {encoding:'utf8',timeout:60000}));
    secrets.push(...Object.values(credentials).map(credential=>credential.token));
    server = spawn(php, ['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',root,router], {
      cwd:root, env:{...process.env,PRESS_MCP_FIXTURE_ROOT:root}, stdio:['ignore','ignore','pipe'],
    });
    server.stderr.on('data', data => { serverLog = (serverLog + data).slice(-8000); });
    let ready = false;
    for (let i=0; i<100; i++) {
      if(server.exitCode !== null) throw new Error('Fixture server failed: '+serverLog);
      try { const response=await fetch(base+'/fixture-ready',{signal:AbortSignal.timeout(500)}); if(await response.text()==='ready'){ready=true;break;} } catch {}
      await delay(100);
    }
    assert(ready,'Fixture server must become ready.');
    const traffic = [];
    const connect = async credential => {
      const client = new Client({name:'batoi-press-http-acceptance',version:'1.0.0'},{capabilities:{}});
      const transport = new StreamableHTTPClientTransport(new URL(base+'/mcp'), {
        requestInit:{headers:{Authorization:`Bearer ${credential.token}`}},
        onInsufficientScope:'throw',
        fetch:async (url, init) => {
          assert.equal(new URL(url).origin,new URL(base).origin,'Never send a fixture credential off loopback.');
          const response = await fetch(url,{...init,redirect:'error'});
          const requestHeaders = new Headers(init?.headers);
          traffic.push({method:init?.method||'GET',status:response.status,version:requestHeaders.get('mcp-protocol-version')});
          return response;
        },
      });
      clients.push(client);
      await client.connect(transport,{timeout:15000});
      assert.equal(client.getServerVersion().name,'Batoi Press');
      return client;
    };
    const reader = await connect(credentials.reader);
    await reader.ping();
    const readerTools = await reader.listTools();
    const names = readerTools.tools.map(tool=>tool.name);
    assert(names.includes('site_get') && names.includes('page_get'));
    assert(!names.includes('page_create_draft') && !names.includes('page_publish'));
    const validator = new Ajv({strict:false});
    for(const tool of readerTools.tools) {
      assert.equal(validator.validateSchema(tool.inputSchema),true,`${tool.name}: valid input schema`);
      assert.equal(validator.validateSchema(tool.outputSchema),true,`${tool.name}: valid output schema`);
    }
    const site = await reader.callTool({name:'site_get',arguments:{}});
    assert.equal(site.structuredContent.url,base);
    const resources = await reader.listResources();
    assert(resources.resources.some(resource=>resource.uri==='batoi://site'));
    const resource = await reader.readResource({uri:'batoi://site'});
    assert.equal(JSON.parse(resource.contents[0].text).url,base);
    assert((await reader.listResourceTemplates()).resourceTemplates.some(resource=>resource.uriTemplate==='batoi://pages/{id}'));
    await assert.rejects(reader.callTool({name:'page_create_draft',arguments:{content:{title:'Denied'},idempotency_key:'http-denied-1'}}));
    // The SDK rejects undiscovered tools locally. Bypass that client safeguard
    // to prove that the server independently enforces the same grant boundary.
    const denied = await fetch(base+'/mcp',{
      method:'POST',redirect:'error',signal:AbortSignal.timeout(15000),
      headers:{Authorization:`Bearer ${credentials.reader.token}`,'Content-Type':'application/json',Accept:'application/json, text/event-stream','MCP-Protocol-Version':'2025-11-25'},
      body:JSON.stringify({jsonrpc:'2.0',id:999,method:'tools/call',params:{name:'page_create_draft',arguments:{content:{title:'Denied direct request'},idempotency_key:'http-direct-denied-1'}}}),
    });
    const deniedBody = await denied.json();
    assert.equal(denied.status,403,'Server refuses direct out-of-scope HTTP calls: '+JSON.stringify(deniedBody));
    assert.equal(deniedBody.error.data.code,'insufficient_scope');

    const editor = await connect(credentials.editor);
    const editorTools = await editor.listTools();
    assert(editorTools.tools.some(tool=>tool.name==='page_create_draft'));
    for(const tool of editorTools.tools) assert.equal(validator.validateSchema(tool.inputSchema),true,`${tool.name}: valid schema`);
    const draftArgs = {name:'page_create_draft',arguments:{content:{title:'SDK draft',slug:'sdk-draft',body:'<p>Unpublished test.</p>'},idempotency_key:'http-create-draft-1'}};
    const draft = (await editor.callTool(draftArgs)).structuredContent.resource;
    assert.equal(draft.status,'draft');
    assert.equal((await editor.callTool(draftArgs)).structuredContent.resource.id,draft.id,'Creation retry is idempotent.');
    const publishArgs = {name:'page_publish',arguments:{id:draft.id,expected_revision:draft.revision,idempotency_key:'http-publish-draft-1'}};
    const proposed = (await editor.callTool(publishArgs)).structuredContent;
    assert.equal(proposed.state,'pending');
    assert.equal(proposed.approval_required,true);
    assert.equal(proposed.review_url,`${base}/admin/proposals/${proposed.id}`);
    assert.equal((await editor.callTool(publishArgs)).structuredContent.id,proposed.id,'Publication retry returns the same proposal.');
    assert.equal((await editor.callTool({name:'page_get',arguments:{id:draft.id}})).structuredContent.status,'draft');
    assert.equal((await editor.callTool({name:'proposal_get',arguments:{id:proposed.id}})).structuredContent.state,'pending');
    await assert.rejects(reader.callTool({name:'proposal_get',arguments:{id:proposed.id}}));
    const live = (await editor.callTool({name:'page_get',arguments:{id:'pg_acceptance_home'}})).structuredContent;
    const change = (await editor.callTool({name:'page_propose_changes',arguments:{id:live.id,changes:{title:'Pending home'},expected_revision:live.revision,idempotency_key:'http-propose-home-1'}})).structuredContent;
    assert.equal(change.state,'pending');
    assert.equal((await editor.callTool({name:'page_get',arguments:{id:live.id}})).structuredContent.title,'Fixture Home');

    const manager = await connect(credentials.manager);
    const managerTools = await manager.listTools();
    assert(managerTools.tools.some(tool=>tool.name==='media_propose_upload'));
    assert(!managerTools.tools.some(tool=>tool.name==='page_list'),'Media/site grants do not grant private editorial access.');
    for(const tool of managerTools.tools) {
      assert.equal(validator.validateSchema(tool.inputSchema),true,`${tool.name}: valid input schema`);
      assert.equal(validator.validateSchema(tool.outputSchema),true,`${tool.name}: valid output schema`);
    }
    const callManager = async (name,args={}) => (await manager.callTool({name,arguments:args})).structuredContent;
    const publicSettings = await callManager('public_settings_get');
    const settingsProposal = await callManager('public_settings_propose_changes',{changes:{tagline:'Proposed SDK tagline'},expected_revision:publicSettings.revision,idempotency_key:'http-settings-1'});
    assert.equal(settingsProposal.state,'pending');
    assert.equal((await callManager('settings_proposal_get',{id:settingsProposal.id})).state,'pending');
    assert.equal((await callManager('public_settings_get')).revision,publicSettings.revision);
    const widgets = await callManager('widgets_get');
    const widgetsProposal = await callManager('widgets_propose_changes',{widgets:[{type:'tag_cloud',title:'SDK tags'}],expected_revision:widgets.revision,idempotency_key:'http-widgets-1'});
    assert.equal((await callManager('widget_proposal_get',{id:widgetsProposal.id})).state,'pending');
    assert.equal((await callManager('widgets_get')).revision,widgets.revision);
    const menu = await callManager('menu_get',{id:'main'});
    const menuProposal = await callManager('menu_propose_changes',{key:'main',changes:{name:'SDK menu proposal'},expected_revision:menu.revision,idempotency_key:'http-menu-1'});
    assert.equal((await callManager('menu_proposal_get',{id:menuProposal.id})).state,'pending');
    assert.equal((await callManager('menu_get',{id:'main'})).revision,menu.revision);
    const mediaBefore = await callManager('media_list');
    const uploadArgs = {upload:{name:'sdk-note.txt',content_base64:Buffer.from('Private pending SDK upload.').toString('base64'),metadata:{title:'SDK upload',alt:'Plain text fixture',caption:'Pending approval'}},idempotency_key:'http-media-1'};
    const mediaProposal = await callManager('media_propose_upload',uploadArgs);
    assert.equal(mediaProposal.state,'pending');
    assert.equal((await callManager('media_propose_upload',uploadArgs)).id,mediaProposal.id);
    assert.equal((await callManager('media_proposal_get',{id:mediaProposal.id})).state,'pending');
    assert.deepEqual(await callManager('media_list'),mediaBefore,'Pending upload is absent from the public library.');
    assert(Array.isArray((await callManager('activity_list',{limit:2})).data));
    let claudeVersion;
    let claudeEnv;
    if (claudeBinary) {
      // No interactive/model session, inherited API keys, global configuration,
      // or real installation credentials. The config stores an env reference.
      claudeEnv = {PATH:process.env.PATH, TMPDIR:tmpdir(), CLAUDE_CONFIG_DIR:join(root,'claude-config'), CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC:'1', ANTHROPIC_BASE_URL:base, PRESS_CLAUDE_FIXTURE_TOKEN:credentials.editor.token};
      const runClaude = args => execFileSync(claudeBinary,args,{cwd:root,env:claudeEnv,encoding:'utf8',timeout:45000,stdio:['ignore','pipe','pipe']});
      claudeVersion = runClaude(['--version']).trim();
      const definition = {type:'http',url:base+'/mcp',headers:{Authorization:'Bearer ${PRESS_CLAUDE_FIXTURE_TOKEN}'}};
      runClaude(['mcp','add-json','--scope','user','press-fixture',JSON.stringify(definition)]);
      const status = runClaude(['mcp','list']);
      assert(/press-fixture:.*Connected/.test(status),'Claude Code must report the synthetic endpoint Connected: '+status);
    }
    execFileSync(php,[router,'revoke',root,credentials.editor.id],{timeout:15000});
    await assert.rejects(editor.callTool({name:'site_get',arguments:{}}));
    if (claudeBinary) {
      const revokedStatus = execFileSync(claudeBinary,['mcp','list'],{cwd:root,env:claudeEnv,encoding:'utf8',timeout:45000,stdio:['ignore','pipe','pipe']});
      assert(!/press-fixture:.*Connected/.test(revokedStatus) && /press-fixture:.*(Needs authentication|Failed to connect)/.test(revokedStatus),'Claude Code must refuse a revoked credential: '+revokedStatus);
    }
    assert(traffic.some(entry=>entry.status===202),'Initialized notification accepted without a body.');
    assert(traffic.some(entry=>entry.status===401),'Revocation enforced over HTTP.');
    assert(traffic.some(entry=>entry.version==='2025-11-25'),'Client uses the negotiated supported protocol.');
    const report={prefix:prefix||'/',readTools:names.length,editorTools:editorTools.tools.length,managerTools:managerTools.tools.length,requests:traffic.length,protocol:'2025-11-25',result:'passed'};
    if (claudeVersion) report.claude={version:claudeVersion,connection:'passed',revocation:'passed',scope:'MCP CLI connection health only; no model/tool execution session'};
    checks.push(report);
    console.log(JSON.stringify(report));
  } finally {
    for(const client of clients) await client.close().catch(()=>{});
    if(server && server.exitCode===null) {
      const exited=new Promise(resolve=>server.once('exit',resolve));
      server.kill('SIGTERM');
      await Promise.race([exited,delay(3000)]);
      if(server.exitCode===null && server.signalCode===null){server.kill('SIGKILL');await exited;}
    }
    // Delete only the generated synthetic fixture owned by this invocation.
    await rm(root,{recursive:true,force:true});
  }
}
try {
  for(const prefix of ['', '/testsite/public_html']) await check(prefix);
  console.log(JSON.stringify({sdk:`@modelcontextprotocol/client@${sdkPackage.version}`,checks:checks.length,result:'passed',scope:'Loopback HTTP SDK acceptance; not Codex/Claude UI or hosted OAuth certification'}));
} catch(error) {
  let message=error.stack||String(error);
  for(const secret of secrets) message=message.replaceAll(secret,'[redacted]');
  console.error(message); process.exitCode=1;
}
