# GradeQuest custom-domain deployment

The Laravel and React application now supports the complete application lifecycle:

1. A school registers `portal.school.edu`.
2. GradeQuest supplies an ownership TXT record and a portal CNAME record.
3. The school verifies ownership.
4. GradeQuest verifies that the CNAME points to `CUSTOM_DOMAIN_CNAME_TARGET`.
5. The domain becomes active and tenant resolution uses the request Host header.
6. The frontend uses same-origin `/api` requests on connected domains.
7. A daily scheduled health check detects broken routing.

## Required production infrastructure

Set these values in the backend environment:

```dotenv
PLATFORM_HOSTS=gradequest.com.ng,www.gradequest.com.ng,app.gradequest.com.ng,api.gradequest.com.ng
CUSTOM_DOMAIN_CNAME_TARGET=domains.gradequest.com.ng
CUSTOM_DOMAIN_TARGET_IPS=203.0.113.10
CUSTOM_DOMAIN_VERIFICATION_PREFIX=_gradequest-verification
CUSTOM_DOMAIN_TLS_ASK_SECRET=replace-with-a-long-random-secret
CUSTOM_DOMAIN_HEALTH_FAILURE_THRESHOLD=3
```

Create DNS for `domains.gradequest.com.ng` pointing to the GradeQuest web edge. Schools then create:

```text
_gradequest-verification.portal.school.edu TXT gradequest-verify=...
portal.school.edu CNAME domains.gradequest.com.ng
```

The included `Caddyfile.custom-domains.example` provides controlled on-demand TLS. Caddy asks Laravel whether a hostname is active before issuing a certificate, preventing arbitrary certificate issuance.

Run Laravel's scheduler continuously so domain health checks execute:

```text
php artisan schedule:run
```

An active domain is disabled after the configured number of consecutive failed routing checks. Successful checks reset the counter.

Before release, run the built-in production readiness check from the deployed backend:

```text
php artisan domains:validate-deployment
php artisan domains:validate-deployment --domain=portal.school.edu
```

The second command additionally confirms that the requested domain is active in GradeQuest and that its live DNS routes to the configured GradeQuest edge.

The web edge must send the original `Host` and forwarded-protocol headers to Laravel. Do not rewrite custom-domain requests to `gradequest.com.ng` before Laravel receives them.
