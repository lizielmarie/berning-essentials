# Berning Essentials: Render + TiDB Cloud Deployment Guide

Please refer to the full deployment guide located at:
[documentation/DEPLOYMENT_RENDER_TIDB.md](documentation/DEPLOYMENT_RENDER_TIDB.md)

### Quick Summary of Render Environment Variables:
- `APP_ENV`: `production`
- `DB_HOST`: `gateway01.ap-southeast-1.prod.aws.tidbcloud.com`
- `DB_PORT`: `4000`
- `DB_NAME`: `berning_essentials`
- `DB_USER`: *(Your TiDB Cloud Username)*
- `DB_PASS`: *(Your TiDB Cloud Password)*
- `DB_SSL_CA_PATH`: `/etc/secrets/isrgrootx1.pem`

### Secret File:
- Mount Path: `/etc/secrets/isrgrootx1.pem`
- Content: Let's Encrypt ISRG Root X1 Root CA Certificate
