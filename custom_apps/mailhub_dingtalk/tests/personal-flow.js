/* SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs'); const path = require('node:path'); const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../js/personal.js'), 'utf8');
async function scenario({ding=true, token=true, matches=true, sdkFails=false, serverFails=false}={}) {
  let submit; const events=[]; const requests=[];
  const fields = {
    'mh-personal': {dataset:{saveUrl:'/password',nonce:'form-nonce',clientId:'client',corpId:'corp'}},
    'mh-password-form': {hidden:false,addEventListener(name,fn) { if(name==='submit') submit=fn; }},
    'mh-password-message': {textContent:''}, 'mh-password-save': {disabled:false},
    'mh-web-password': {value:'Correct Horse 2026!'}, 'mh-web-confirm': {value:matches?'Correct Horse 2026!':'Other Horse 2026!'},
    'mh-password-retry': {hidden:true,addEventListener(){}}, 'mh-web-username':{value:'dt_existing'},
    'mh-copy-username':{addEventListener(){}},
  };
  const document={head:{dataset:{requesttoken:token?'csrf-token':''}},readyState:'complete',getElementById(id){return fields[id];}};
  const context={document,navigator:{userAgent:ding?'DingTalk':'Browser'},location:{protocol:'https:',reload(){}},
    window:{MailhubDingTalk:{async requestCode(){events.push('identity');if(sdkFails)throw new Error('Identity failed');return 'fresh-code';}}},
    async fetch(url,options){events.push('save');requests.push({url,options});return {ok:!serverFails,async json(){return {message:serverFails?'Request rejected':'Saved and signed out'};}};}};
  vm.runInNewContext(source,context);
  if(submit) await submit({preventDefault(){}});
  return {fields,events,requests,submit};
}
(async()=>{
  let r=await scenario({ding:false});
  assert.equal(r.fields['mh-password-save'].disabled,true,'ordinary browser cannot request DingTalk password reset');
  assert.deepEqual(r.events,[],'ordinary browser triggers no identity or password requests');
  r=await scenario({matches:false});
  assert.deepEqual(r.events,[],'mismatched passwords never reach SDK or server');
  r=await scenario({token:false});
  assert.deepEqual(r.events,[],'missing native CSRF blocks identity and password requests');
  r=await scenario({sdkFails:true});
  assert.deepEqual(r.events,['identity'],'failed fresh identity never posts password');
  assert.equal(r.fields['mh-web-password'].value,'','SDK failure clears password field');
  r=await scenario({serverFails:true});
  assert.equal(r.fields['mh-password-retry'].hidden,false,'server refusal provides a fresh-page retry');
  assert.equal(r.fields['mh-web-confirm'].value,'','server refusal clears confirmation');
  r=await scenario();
  assert.deepEqual(r.events,['identity','save'],'fresh identity precedes a single password submission');
  assert.equal(r.requests[0].options.headers.requesttoken,'csrf-token','native CSRF sent in header');
  assert.deepEqual(JSON.parse(r.requests[0].options.body),{nonce:'form-nonce',code:'fresh-code',password:'Correct Horse 2026!',confirmation:'Correct Horse 2026!'},'body contains current code and nonce, no selectable target account');
  assert.equal(r.fields['mh-password-form'].hidden,true,'success removes password form');
  assert.equal(r.fields['mh-web-password'].value,'','success clears password field');
  assert.equal(r.fields['mh-personal'].dataset.nonce,'','success discards consumed nonce');
  await r.submit({preventDefault(){}});
  assert.equal(r.requests.length,1,'double submit cannot replay password change');
  console.log('PASS 15 personal-password browser checks; no network requests');
})().catch(error=>{console.error(error);process.exitCode=1;});
