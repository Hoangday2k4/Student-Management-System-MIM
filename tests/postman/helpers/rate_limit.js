#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const envPath = path.join(__dirname, '..', 'env.postman_environment.json');
const env = JSON.parse(fs.readFileSync(envPath, 'utf8'));
const base = env.values.find(v=>v.key==='INTEGRATION_BASE_URL')?.value || 'http://127.0.0.1:8001';
const studentCookie = env.values.find(v=>v.key==='session_student')?.value || '';

async function run() {
  const url = base + '/home';
  const max = 30;
  console.log('Rate-limit test: sending', max, 'requests to', url);
  const results = [];
  const headers = {};
  if (studentCookie) headers['Cookie'] = studentCookie;

  const fetch = global.fetch || require('node-fetch');

  for (let i=0;i<max;i++){
    try{
      const res = await fetch(url, { method: 'GET', headers });
      results.push(res.status);
      console.log(i+1, res.status);
    }catch(e){
      console.error('request error', e.message);
      results.push(0);
    }
    await new Promise(r=>setTimeout(r, 100));
  }

  const has429 = results.includes(429);
  const okRatio = results.filter(s=>s>=200 && s<300).length / max;
  console.log('Summary: 2xx ratio', okRatio, 'has429=', has429);
  if (!has429 && okRatio>0.9) {
    console.warn('No 429 observed; rate limit may be absent or very high');
    process.exit(1);
  }
  process.exit(0);
}

run();
