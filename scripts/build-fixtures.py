"""Author / Creator / Last Modified By: Dhruba Poudel."""
from pathlib import Path
import json

root=Path(__file__).resolve().parents[1]
def order(oid,amount=12900,currency='AUD',status='processing'):
    return {'order_id':oid,'reference':f'DEMO-{oid}','amount_minor':amount,'currency':currency,'status':status}
orders=[order(8101),order(8102,4990),order(8103,7800),order(8104,3500),order(8105,8900,status='completed'),order(8106,2100),order(8107,11900,status='completed'),order(8108,6500),order(8110,4200)]
def event(oid,eid,seq=1,**changes):
    data=next((x for x in orders if x['order_id']==oid),order(oid,2400)).copy()
    data.update(event_id=eid,sequence=seq,occurred_at='2026-10-27T00:00:00Z')
    data.update(changes)
    return data
events=[event(8101,'evt-match'),event(8102,'evt-amount',amount_minor=5990),event(8103,'evt-currency',currency='USD'),event(8104,'evt-reference',reference='OTHER-8104'),event(8105,'evt-status',status='processing'),event(8106,'evt-duplicate'),event(8106,'evt-duplicate'),event(8107,'evt-new',2),event(8107,'evt-old',1,status='processing',occurred_at='2026-10-26T23:59:00Z'),event(8108,'evt-conflict',amount_minor=6500),event(8108,'evt-conflict',amount_minor=6600),event(8109,'evt-missing'),event(8110,'evt-sequence-a',2),event(8110,'evt-sequence-b',2)]
fixture={'schema_version':1,'scenario_id':'fictional-commerce-review-2026-10','snapshot_at':'2026-10-27T00:00:00Z','orders':orders,'events':events}
for path in (root/'fixtures/scenario.json',root/'plugin/commerce-reconcile/fixtures/scenario.json'):
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_text(json.dumps(fixture,indent=2)+'\n',encoding='utf-8')
print('Fictional scenario written: 9 orders, 14 events. Expected 3 matched, 9 exception rows, 2 ignored.')
