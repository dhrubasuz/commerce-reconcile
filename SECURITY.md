# Security scope

Maintained by **Dhruba Poudel**.

Commerce Reconcile is an inspectable development showcase. Its published PHP endpoint accepts only four bundled fictional scenario names and performs no writes or outbound service calls. It does not accept credentials or arbitrary customer/order information.

The separately installable WordPress plugin requires an appropriate capability and REST nonce, validates bounded JSON and can read exact WooCommerce order IDs. It does not charge, refund, save an order, store provider credentials or fetch a caller-supplied URL. It is not a durable webhook inbox or background worker; any prior ledger supplied to the pure domain engine is trusted caller state, not verified organisational approval.

Use an isolated development site and fictional information when evaluating the components. The source tests and runtime checks are bounded evidence; no security certification, production service guarantee or universal compatibility is claimed. Do not include real personal, business or security-sensitive information in a public issue.

If a report needs confidential detail, contact the repository owner privately through a verified existing channel before sharing it. No dedicated private disclosure endpoint is advertised here.
