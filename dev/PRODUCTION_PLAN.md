# Production deployment gate (prepare only)

The current artifact-based staging test and production cutover procedure is in [release/README.md](release/README.md). The staging checkout, COD, HPOS, protected routes, signed Easebuzz callbacks, and mocked recovery have passed with synthetic data. No live Easebuzz transaction has been made.

**Current gate: NO-GO.** Production WordPress still advertises HTTP and serves HTTP without redirect; staging-domain acceptance and merchant-funded testing are unconfirmed; the staging recovery cron entry awaits approval; real Easebuzz Transaction V2 responses and live shipping rates remain unverified. Production files, settings, MySQL, Razorpay, COD, and Shiprocket remain unchanged.
