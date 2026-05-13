#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const fetch = global.fetch || require('node-fetch');

const argv = require('minimist')(process.argv.slice(2));
const studentCode = argv.student || process.env.LAST_CREATED_STUDENT || argv.s;
const envPath = path.join(__dirname, '..', 'env.postman_environment.json');
let base = 'http://127.0.0.1:8001';
if (fs.existsSync(envPath)){
  try{ const env = JSON.parse(fs.readFileSync(envPath,'utf8')); base = env.values.find(v=>v.key==='INTEGRATION_BASE_URL')?.value || base; }catch(e){}
}

if (!studentCode){
  console.error('No student code provided. Use --student STUDENT_CODE or set LAST_CREATED_STUDENT env var.');
  process.exit(1);
}

(async ()=>{
  const url = base + '/student.php?student_code=' + encodeURIComponent(studentCode);
  console.log('Deleting student', studentCode, '->', url);
  try{
    const res = await fetch(url, { method: 'DELETE' });
    console.log('Status', res.status);
    const txt = await res.text();
    console.log(txt.substring(0,1000));
    process.exit(res.status===200?0:1);
  }catch(e){ console.error('error', e); process.exit(2); }
})();
