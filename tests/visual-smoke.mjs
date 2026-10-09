import assert from 'node:assert/strict';
import {readFile,mkdir} from 'node:fs/promises';
import {resolve} from 'node:path';
import {chromium} from 'playwright';
const env=Object.fromEntries((await readFile('../.env','utf8')).split('\n').filter(x=>/^[A-Z_]+=/.test(x)).map(line=>{const i=line.indexOf('=');return[line.slice(0,i),line.slice(i+1)];}));
const browser=await chromium.launch(process.env.E2E_CHROMIUM_EXECUTABLE?{executablePath:process.env.E2E_CHROMIUM_EXECUTABLE}:{});
try{
  const page=await browser.newPage({viewport:{width:1440,height:1050}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(process.env.E2E_BASE_URL??env.APP_ORIGIN);await page.getByLabel('Имя пользователя').fill(env.ADMIN_USERNAME);await page.getByLabel('Пароль',{exact:true}).fill(env.ADMIN_PASSWORD);await page.getByRole('button',{name:'Войти',exact:true}).click();
  await page.getByRole('heading',{name:/^Товары/}).waitFor();await page.locator('.product-table tbody tr').first().waitFor();assert.equal(await page.locator('.product-table tbody tr').count(),20);assert.deepEqual(errors,[]);
  const out=process.env.E2E_OUTPUT_DIR??'../backend/var/e2e';await mkdir(out,{recursive:true});await page.screenshot({path:resolve(out,'catalog.png'),fullPage:true});console.log('Catalog visual smoke passed:20 paginated rows, no uncaught browser errors.');
}finally{await browser.close();}
