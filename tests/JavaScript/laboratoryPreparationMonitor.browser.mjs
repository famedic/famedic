// After npm run build, run in a browser-capable Node runtime.
// The existing node container can export --prepare-only; then set
// MONITOR_BROWSER_FIXTURE and MONITOR_BROWSER_EXECUTABLE on the host.
// Real monitor/detail, Catalyst components and SidebarLayout; navigation and
// unrelated authenticated chrome are stubbed. No app database or HTTP providers.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { build } from 'esbuild';
import puppeteer from 'puppeteer';

const root = process.cwd();
let fixture;
if (process.env.MONITOR_BROWSER_FIXTURE) {
    fixture = JSON.parse(fs.readFileSync(process.env.MONITOR_BROWSER_FIXTURE, 'utf8'));
} else {
const bundle = await build({
    stdin: { contents: `import React from 'react'; import {createRoot} from 'react-dom/client';
        import Monitor from './resources/js/Pages/Admin/LaboratoryPreparation/Monitor.jsx';
        import Detail from './resources/js/Pages/Admin/LaboratoryPreparation/MonitorDetail.jsx';
        window.renderMonitor = (detail, props) => { window.monitorReactRoot ||= createRoot(document.getElementById('root'));
            window.monitorReactRoot.render(React.createElement(detail ? Detail : Monitor, props)); };`,
        resolveDir: root, loader: 'jsx' },
    bundle: true, write: false, format: 'iife', jsx: 'automatic',
    define: { 'process.env.NODE_ENV': '"production"' },
    alias: { '@': path.join(root, 'resources/js') },
    plugins: [{ name: 'isolated-monitor-shell', setup(builder) {
        builder.onResolve({filter: /^@\/Layouts\/AdminLayout$/}, () => ({path: 'layout', namespace: 'fixture'}));
        builder.onResolve({filter: /^@inertiajs\/react$/}, () => ({path: 'inertia', namespace: 'fixture'}));
        builder.onLoad({filter: /.*/, namespace: 'fixture'}, args => ({loader: 'jsx', resolveDir: root,
            contents: args.path === 'layout'
                ? `import React from 'react'; import {SidebarLayout} from './resources/js/Components/Catalyst/sidebar-layout.jsx';
                    export default function Layout({children}) { return <SidebarLayout navbar={<span>Famedic QA</span>}
                        sidebar={<nav>Administración QA</nav>}>{children}</SidebarLayout>; }`
                : `import React, {forwardRef} from 'react'; export const router={get:(...args)=>window.navigation=args};
                    export const Link=forwardRef(({children,...props},ref)=><a {...props} ref={ref}>{children}</a>);` }));
    }}],
});
const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
const css = manifest['resources/js/app.jsx'].css.map(file => fs.readFileSync('public/build/'+file, 'utf8')).join('\n');
const token = 'INDICACIONORIGINALMUYEXTENSA'.repeat(30);
const row = {id: 101, folio: token, brand: 'OLAB', created_at: '2026-10-16T12:00:00Z',
    decision_status: 'FALLBACK_ORIGINAL', technical_status:'failed', openai_status:'failed',
    needs_provider_review: true, detail_url:'/admin/laboratory-preparation/monitor/101?return%5Bsearch%5D=QA'};
const metricIds = ['total','auto','fallback','clinical','source_quality','technical','pending','review','openai_ok','openai_failed','shadow'];
const flags = {deterministic_v3_enabled:true};
const options = Object.fromEntries(['brands','decisionStatuses','technicalStatuses','fallbackCategories','openAiStatuses','modes','rules'].map(key=>[key,[]]));
const monitor = {filters:{search:'QA'}, filterOptions:options, metrics: metricIds.map(id=>({id,label:id,value:1,tone:'default'})),
    purchases:{data:[row],links:[{label:'1',url:'?page=1',active:true}],prev_page_url:null,next_page_url:null},
    universeNote:'Compras con resumen o ejecución de preparación.',semanticsNote:'Ejecución relevante.',featureFlags:flags};
const detail = {purchase:{id:101,folio:token,brand:'OLAB'},diagnostic:{decision_status:'FALLBACK_ORIGINAL',rules_applied:['R01']},
    openAi:{status:'failed',error:'La ejecución no pudo completarse.'},featureFlags:flags,back_url:'/admin/laboratory-preparation/monitor?search=QA',
    history:{executions:[],has_more:false},indications:{original:[{id:1,study_id:'1',study_name:token,indications:token}],
        patient_presenter:{has_ai_summary:false,ai_status:'FALLBACK_ORIGINAL',studies:[{id:1,name:token,instructions:token}]},
        summary_json:{long_value:token},consolidated_requirements:{long_value:token}}};
fixture = {bundleText:bundle.outputFiles[0].text, css, monitor, detail};
if (process.argv.includes('--prepare-only')) {
    fs.writeFileSync('storage/logs/block-a-browser-fixture.json', JSON.stringify(fixture));
    process.exit(0);
}
}
const {bundleText,css,monitor,detail}=fixture;
const browser = await puppeteer.launch({headless:true,executablePath:process.env.MONITOR_BROWSER_EXECUTABLE || undefined,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0;
try {
    const page = await browser.newPage();
    const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.setRequestInterception(true); page.on('request',request=>request.abort());
    await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><div id="root"></div></body></html>');
    await page.addStyleTag({content:css}); await page.addScriptTag({content:bundleText});
    for (const width of [320,375,768,1024,1280,1440]) {
        await page.setViewport({width,height:1000});
        for (const isDetail of [false,true]) {
            await page.evaluate((isDetail,props)=>window.renderMonitor(isDetail,props),isDetail,isDetail?detail:monitor);
            await page.waitForFunction(()=>document.querySelector('h1'));
            await page.evaluate(()=>document.querySelectorAll('details').forEach(el=>el.open=true));
            await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
            const layout=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,
                columns:document.querySelectorAll('thead th').length,
                detail: [...document.querySelectorAll('a')].some(a=>a.href.includes('search'))}));
            assert.ok(layout.scroll<=layout.width, `${isDetail?'detail':'monitor'} overflow at ${width}: ${layout.scroll}`);
            assert.ok(layout.detail,'Filter-preserving detail/back link');
            if(!isDetail) assert.equal(layout.columns,5);
            assert.deepEqual(errors,[]); checks++;
            if(width===375 && !isDetail && process.env.MONITOR_SCREENSHOT) await page.screenshot({path:process.env.MONITOR_SCREENSHOT,fullPage:true});
        }
    }
    console.log(JSON.stringify({passed:checks,widths:[320,375,768,1024,1280,1440],horizontalOverflow:false,
        fixtureShell:true,realBrowserZoom200:'manual pending'}));
} finally { await browser.close(); }
