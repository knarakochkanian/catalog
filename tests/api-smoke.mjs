import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
const here=dirname(fileURLToPath(import.meta.url));
const env=Object.fromEntries((await readFile(resolve(here,'../.env'),'utf8')).split('\n').filter(line=>/^[A-Z_]+=/.test(line)).map(line=>{const i=line.indexOf('=');return [line.slice(0,i),line.slice(i+1)];}));
const base=process.env.E2E_BASE_URL??env.APP_ORIGIN;
let cookie='';let csrf='';
async function request(path,options={}) {
  const response=await fetch(base+path,{...options,headers:{Origin:base,...(cookie?{Cookie:cookie}:{}),...options.headers}});
  const set=response.headers.getSetCookie();const sessionCookie=set.find(value=>value.startsWith('PHPSESSID='));
  if(sessionCookie) cookie=sessionCookie.split(';')[0];
  return response;
}
assert.equal((await request('/api/health')).status,200);
assert.equal((await request('/api/products')).status,401);
let session=await(await request('/api/auth/session')).json();csrf=session.csrf_token;
assert.equal((await request('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})).status,403);
session=await(await request('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({username:process.env.ADMIN_USERNAME??env.ADMIN_USERNAME,password:process.env.ADMIN_PASSWORD??env.ADMIN_PASSWORD})})).json();
assert.equal(session.authenticated,true);csrf=session.csrf_token;
assert.equal((await request('/api/products?page=0')).status,422);
assert.equal((await request('/api/products?min_price=20&max_price=10')).status,422);
assert.equal((await request('/api/products',{headers:{Origin:'https://unrelated.example'}})).status,403);
const form=new FormData();form.set('file',new Blob([await readFile(resolve(here,'../backend/tests/fixtures/e2e.xlsx'))]),'e2e.xlsx');
const upload=await request('/api/imports',{method:'POST',headers:{'X-CSRF-Token':csrf},body:form});assert.equal(upload.status,202);
const job=await upload.json();
if(process.env.E2E_LOCAL_WORKER==='1') execFileSync(process.env.E2E_PHP_BIN??'php',[resolve(here,'process-job.php'),job.id],{env:process.env,stdio:'pipe',timeout:20000});
let report;
for(let i=0;i<30;i++) {
  report=await(await request(`/api/imports/${job.id}`)).json();
  if(report.status==='completed'||report.status==='failed') break;
  await new Promise(resolve=>setTimeout(resolve,500));
}
assert.equal(report.status,'completed');assert.equal(report.processed,3);assert.equal(report.skipped,1);assert.equal(report.errors[0].row,4);assert.equal(report.created+report.updated,2);
const first=await(await request('/api/products?page=1&limit=1')).json();const second=await(await request('/api/products?page=2&limit=1')).json();
assert.equal(first.items.length,1);assert.equal(second.items.length,1);assert.notEqual(first.items[0].id,second.items[0].id);
const found=await(await request('/api/products?name=E2E%20&min_price=1300&max_price=1400')).json();assert.equal(found.total,1);
const detail=await(await request('/api/products/'+found.items[0].id)).json();assert.equal(detail.external_code,'000001');assert.equal(detail.price,'1320.00');assert.equal(detail.discount,'33.3333');assert.equal(detail.attributes.find(a=>a.key==='Ноль').value,'0');
const existing=await(await request('/api/products/'+first.items[0].id)).json();
if(existing.images.length) {const image=await request(existing.images[0].path);assert.equal(image.status,200);assert.match(image.headers.get('content-type'),/^image\//);assert.ok((await image.arrayBuffer()).byteLength>0);}
const exported=await request('/api/products/export');assert.equal(exported.status,200);assert.equal(exported.headers.get('content-type'),'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');const bytes=new Uint8Array(await exported.arrayBuffer());assert.equal(String.fromCharCode(...bytes.slice(0,2)),'PK');
assert.equal((await request('/api/auth/logout',{method:'POST',headers:{'X-CSRF-Token':csrf}})).status,204);
assert.equal((await request('/api/products')).status,401);
console.log('API smoke passed: health, auth/session, CSRF, Origin, query validation, upload/status/errors, pagination/filter, leading zeros/decimals/attributes, protected image, XLSX export, logout.');
