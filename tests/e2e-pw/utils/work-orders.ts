import { Page, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

export async function authoriseBrowserRoute(page: Page, projectId: string, suffix: string): Promise<string> {
    const api='/admin/v2/project-progress/api/', csrf=(await page.locator('meta[name=csrf-token]').getAttribute('content'))!;
    const form=await (await page.request.get(api+'work-orders/form')).json();
    const project=form.projects.find((p:{id:number})=>String(p.id)===projectId);
    expect(project?.can_manage).toBeTruthy();
    let item=form.items.find((i:{company_id:number|null})=>!i.company_id||i.company_id===project.company_id);
    const headers={'X-CSRF-TOKEN':csrf,Accept:'application/json'};
    if(!item){
        const schema=await (await page.request.get(api+'finance/items/form?company_id='+project.company_id)).json();
        const response=await page.request.post(api+'finance/items',{headers,data:{request_id:randomUUID(),values:{company_id:project.company_id,name:'Progress fixture Item '+suffix,type:'service',price:'15',cost:'10',category_id:schema.lookups.categories[0].id,uom_id:schema.lookups.uoms[0].id,uom_po_id:schema.lookups.uoms[0].id,enable_purchase:true,enable_sales:true}}});
        expect(response.status()).toBe(201);item={id:(await response.json()).id};
    }
    const route=randomUUID();
    const companyForm=await (await page.request.get(api+'finance/purchase-orders/form?company_id='+project.company_id)).json();
    const response=await page.request.post(api+'work-orders',{headers,data:{request_id:randomUUID(),values:{project_id:Number(projectId),reference:'E2E-WO-'+suffix,partner_id:form.partners.find((p:{company_id:number|null})=>!p.company_id||p.company_id===project.company_id).id,currency_id:companyForm.defaults.currency_id,title:'Progress browser fixture',reason:'Synthetic awarded route for progress workflow test',routes:[{key:route,name:'Browser route '+suffix,chainage_from:'0+000',chainage_to:'1+000'}],lines:[{key:randomUUID(),route_key:route,item_id:item.id,description:'Synthetic awarded item',quantity:'1000',maincon_rate:'15',subcon_rate:'10'}]}}});
    expect(response.status()).toBe(201);
    const wo=await response.json(),version=wo.versions[0];
    const approval=await page.request.post(api+'work-orders/'+wo.id+'/versions/'+version.id+'/approve',{headers,data:{revision:version.revision}});
    expect(approval.status()).toBe(200);
    const workspace=await (await page.request.get(api+'projects/'+projectId+'/workspace')).json();
    return String(workspace.routes.find((r:{name:string})=>r.name==='Browser route '+suffix).work_order_route_id);
}
