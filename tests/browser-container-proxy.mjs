import http from 'node:http';
import { spawn } from 'node:child_process';
const publicURL=new URL(process.env.E2E_BASE_URL??'http://localhost:8080');
const target=new URL(process.env.E2E_PROXY_TARGET??'http://nginx');
const proxy=http.createServer((incoming,outgoing)=>{
  const forwarded=http.request({hostname:target.hostname,port:target.port||80,path:incoming.url,method:incoming.method,headers:incoming.headers},response=>{outgoing.writeHead(response.statusCode??502,response.headers);response.pipe(outgoing);});
  forwarded.on('error',()=>{outgoing.writeHead(502);outgoing.end('Test proxy connection failed');});incoming.pipe(forwarded);
});
proxy.listen(Number(publicURL.port)||80,'127.0.0.1',()=>{
  const child=spawn(process.execPath,[process.argv[2]??'e2e.mjs'],{stdio:'inherit',env:process.env});
  child.on('exit',code=>{proxy.close(()=>process.exit(code??1));});
});
