# Sri Lanka — the 25 districts (checkout address data)

WooCommerce core ships Sri Lanka's **9 provinces**, but couriers quote, route and price by
**district**. This is the list `slk-checkout` installs via the `woocommerce_states` filter
(plan §5). Keys must eventually match Koombiyo's district names exactly — confirm against their
merchant portal before the first live dispatch.

| Province | Districts |
|---|---|
| Western | Colombo · Gampaha · Kalutara |
| Central | Kandy · Matale · Nuwara Eliya |
| Southern | **Galle** · Matara · Hambantota |
| Northern | Jaffna · Kilinochchi · Mannar · Vavuniya · Mullaitivu |
| Eastern | Batticaloa · Ampara · Trincomalee |
| North Western | Kurunegala · Puttalam |
| North Central | Anuradhapura · Polonnaruwa |
| Uva | Badulla · Monaragala |
| Sabaragamuwa | Ratnapura · Kegalle |

**25 districts total.** Galle is the origin (the atelier), so it is both a shipping destination
and the store's own address.

## How this is used

- **Checkout:** required "District" dropdown replacing WooCommerce's province list; postcode
  optional (Sri Lankan shoppers frequently do not know theirs); `address_2` hidden; order notes
  relabelled "Delivery notes (nearest landmark/junction)" because that is what couriers act on.
- **Shipping zones:** at minimum Colombo/Gampaha/Kalutara (metro) vs the rest of the island —
  courier rates differ roughly Rs. 150 vs Rs. 250–290 per parcel.
- **Courier handoff:** the district string is what gets typed into (later, POSTed to) Koombiyo,
  so drift between our labels and theirs becomes a dispatch error. Single source of truth lives
  in `slk-checkout`, not scattered across theme files.
