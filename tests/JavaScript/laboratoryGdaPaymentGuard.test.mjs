import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function setup(response) {
    const visits=[]; const messages=[]; let calls=0;
    const source=fs.readFileSync(new URL('../../resources/js/lib/paypal/createLaboratoryPayPalHandlers.js',import.meta.url),'utf8')
        .replace(/^import .*;\r?\n/gm,'').replace('export function createLaboratoryPayPalHandlers','function createLaboratoryPayPalHandlers');
    const context={axios:{post:async()=>{calls++;return {data:response};}},router:{visit:url=>visits.push(url)},
        route:(name,params)=>name+JSON.stringify(params||{}),document:{querySelector:()=>null},console};
    vm.createContext(context);vm.runInContext(source+'\nthis.factory=createLaboratoryPayPalHandlers;',context);
    const handlers=context.factory({laboratoryBrand:'olab',patientId:1,addressId:1,totalCents:100,
        mutex:{runCreate:fn=>fn(),runCapture:fn=>fn(),releaseCheckoutSession(){}},onUserMessage:msg=>messages.push(msg)});
    return {handlers,visits,messages,get calls(){return calls;}};
}

test('a received payment at creation redirects to the existing purchase and prevents another payment request',async()=>{
    const qa=setup({status:'gda_uncertain',laboratory_purchase_id:123});
    await assert.rejects(qa.handlers.createOrder(),/pago ya fue recibido/);
    qa.handlers.onError(new Error('SDK error'));qa.handlers.onCancel();
    await assert.rejects(qa.handlers.createOrder(),/pago ya fue recibido/);
    await qa.handlers.onApprove({orderID:'QA'});
    assert.equal(qa.calls,1);assert.equal(qa.visits.length,1);assert.match(qa.visits[0],/123/);
    assert.match(qa.messages.at(-1),/pago ya fue recibido/);
});

test('an uncertain captured payment does not suggest another capture or overwrite the received message',async()=>{
    const qa=setup({status:'gda_uncertain',laboratory_purchase_id:456});
    await qa.handlers.onApprove({orderID:'QA'});await qa.handlers.onApprove({orderID:'QA'});
    qa.handlers.onError(new Error('SDK error'));
    assert.equal(qa.calls,1);assert.equal(qa.visits.length,1);assert.match(qa.messages.at(-1),/pago ya fue recibido/);
});

test('the confirmed legacy success response retains purchase navigation',async()=>{
    const qa=setup({status:'captured',laboratory_purchase_id:789});
    await qa.handlers.onApprove({orderID:'QA'});
    assert.equal(qa.calls,1);assert.equal(qa.visits.length,1);assert.match(qa.visits[0],/789/);
});


test('an unresolved capture response blocks another request without claiming a confirmed payment',async()=>{
    const qa=setup({status:'payment_uncertain',message:'Estamos verificando el estado de tu pago.'});
    await qa.handlers.onApprove({orderID:'QA'});qa.handlers.onError(new Error('SDK error'));
    await qa.handlers.onApprove({orderID:'QA'});
    assert.equal(qa.calls,1);assert.equal(qa.visits.length,0);assert.match(qa.messages.at(-1),/verificando el estado/);
});
