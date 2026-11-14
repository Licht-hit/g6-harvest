# Simpleng Gabay sa G6 Harvest

## Ano ang G6 Harvest?

Ang G6 Harvest ay system para sa farm operations at online na pagbili ng gulay. Isang bahagi nito ang pamamahala ng tanim at stock; isa pa ang shop kung saan puwedeng umorder ang buyer.

Isipin ang application bilang opisina at ang database bilang filing cabinet nito. Ang application ang nagpapakita ng mga page at nagpapatakbo ng mga proseso. Ang database ang nagtatago ng accounts, gulay, inventory, orders, at iba pang records.

## Paano dumadaloy ang trabaho?

1. Nagdaragdag ang admin ng gulay, presyo, kategorya, at iba pang detalye.
2. Nagtatala ang staff o farmer ng mga tanim at inaasahang petsa ng ani.
3. Kapag may ani, itinatala ang dami nito at ina-update ang inventory.
4. Namimili ang buyer, naglalagay ng items sa cart, at gumagawa ng order.
5. Naka-save sa order ang kabuuan, address, paraan ng bayad, at delivery status. Naka-save naman ang bawat gulay at dami bilang order item.
6. Ina-update ng staff o admin ang order habang inihahanda at idinedeliver ito. Maaari ring magpadala ang buyer ng return request.

Gumagamit ang inventory ng FIFO: ang stock na unang naidagdag ang unang ginagamit sa order.

Magkahiwalay ang:

- **Payment status:** pending, paid, failed, o refunded.
- **Order status:** confirmed, preparing, ready for delivery, out for delivery, delivered, o cancelled.

Ibig sabihin, puwedeng bayad na ang order pero hindi pa naihahatid.

## Mga gumagamit

- **Buyer:** nagba-browse, nag-o-order, nagta-track ng order, at maaaring mag-request ng return.
- **Staff/Farmer:** namamahala ng tanim, ani, inventory, quality checks, at order fulfillment.
- **Admin:** may access sa farm tools at karagdagang admin records gaya ng Activity log.

## Mahahalagang database records

| Record/table | Simpleng paliwanag |
|---|---|
| `users` / `profiles` | Account, pangalan, email, at role ng user |
| `vegetables` | Mga gulay na ibinebenta at ang presyo, unit, kategorya, at detalye |
| `plantings` | Ano at gaano karami ang itinanim |
| `harvests` | Dami at petsa ng naani mula sa planting |
| `inventory` | Kasalukuyang dami ng stock |
| `inventory_lots` | Mga batch ng stock para sa FIFO |
| `orders` | Buod ng order, buyer, bayad, address, at delivery status |
| `sales` | Bawat gulay at quantity na kasama sa isang order |
| `payment_history` | Mga event at pagbabago sa bayad |
| `quality_inspections` | Grade at notes para sa naaning gulay |
| `return_requests` | Mga return request ng buyer at desisyon ng staff |
| `activity_logs` | History ng mahahalagang aksyon ng mga user |
| `notifications` | Mga alerto tungkol sa order o return |

## Dalawang magkaibang database

### Localhost: PHP at MySQL

Ito ang bersyon na binubuksan sa computer gamit ang local web server. Ang PHP pages ay nasa `auth/`, `buyer/`, at `modules/`; ang `config/database.php` ang nagkokonekta sa MySQL.

- Para sa bagong database, ang [database.sql](./database.sql) ang gumagawa ng schema at sample na catalog data.
- Para sa umiiral nang database, gumawa muna ng backup at gamitin ang [database-upgrade.sql](./database-upgrade.sql) para idagdag ang mga bagong pagbabago.

### Live website: Vercel at Supabase

Ang live na bersyon ay nasa [Harvest website](https://g6-harvest-eta.vercel.app/). Ang frontend at pages nito ay nasa `vercel-app/`; Supabase ang ginagamit nito para sa PostgreSQL database at account authentication. Ang mga schema update ay nasa `vercel-app/supabase/migrations/`.

Sa live database, `auth.users` ang bahagi ng Supabase para sa login, at `profiles` ang naglalaman ng app profile at role. Sa local database, nasa `users` table ang account details at role.

## Mahalagang tandaan

- Magkahiwalay ang local MySQL at live Supabase. Hindi kusang lumilipat ang pagbabago sa presyo, stock, account, o order mula sa isa papunta sa isa.
- May import script sa `vercel-app/scripts/import-mysql.js` para ilipat ang MySQL data sa Supabase; hindi ito tuloy-tuloy na synchronization.
- Ang online payment ay nangangailangan ng provider credentials at tamang public HTTPS webhook setup. Huwag ilagay ang secret keys sa browser JavaScript o i-commit ang mga ito sa Git.
- Pag-apruba ng return request ay hindi kusang nagbabalik ng pera o stock; hiwalay na kailangang asikasuhin ang mga iyon.
- Ang `tests/` ay naglalaman ng automated checks. Ang database integration test ay para lang sa hiwalay na test database na nagtatapos sa `_test`.

