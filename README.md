# AD Management API

![Symfony](https://img.shields.io/badge/Symfony-7.2-blue)
![PHP](https://img.shields.io/badge/PHP-8.2-blue)
![Docker](https://img.shields.io/badge/Docker-Ready-blue)
![License](https://img.shields.io/badge/license-MIT-green.svg)

## 👉 Project Overview

A Symfony-based REST API for managing users and groups via LDAP/Active Directory.

**Features:**
- Search users by `samAccountName` or `Email`
- Unlock, disable, and enable users
- Reset user passwords
- Add or remove users from groups
- List groups & display group members
- API documentation via Swagger UI

> **Note:** LDAP and LDAPS (SSL) connections are dynamically controlled via the `.env` configuration.

---

## 👥 Target Audience

- IT Administrators
- DevOps Teams
- Service Desk Integrations (e.g., Self-Service Password Reset)

---

## 🔧 Tech Stack

- PHP 8.2
- Symfony 6.4
- Docker Compose
- NelmioApiDocBundle v5 (Swagger UI)

---

## 🌐 Quickstart

### 1. Clone the repository

```bash
git clone https://github.com/YOUR_GITHUB_USER/ad-management-api.git
cd ad-management-api
```

### 2. Start Docker environment

```bash
docker compose up -d
```

- Symfony will be available at: [http://localhost:8000](http://localhost:8000)
- Swagger UI will be available at: [http://localhost:8000/api/doc](http://localhost:8000/api/doc)

### 3. Configure `.env.local`

Create a `.env.local` file inside the `app/` directory:

```dotenv
LDAP_HOST=ldaps://domain-constroller.my.domain
LDAP_PORT=636
LDAP_BASE_DN=dc=my,dc=domain
LDAP_USER_DN=DomainUser/Admin@my.domain
LDAP_PASSWORD=YOUR_PASSWORD
```

**Notes:**
- Use `ldap://` and port `389` for unencrypted connections
- Use `ldaps://` and port `636` for SSL/TLS secured connections

### 4. Install Composer dependencies

(Handled automatically by `init.sh` during Docker startup)  
Or manually:

```bash
docker compose exec php composer install
```

---

## 📑 Available API Endpoints

All endpoints are documented in [Swagger UI](http://localhost:8000/api/doc)!

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/api/user/search` | Search for users |
| POST | `/api/user/{samAccountName}/unlock` | Unlock a user |
| POST | `/api/user/{samAccountName}/disable` | Disable a user |
| POST | `/api/user/{samAccountName}/enable` | Enable a user |
| POST | `/api/user/{samAccountName}/reset-password` | Reset a user's password |
| GET | `/api/group/list` | List all groups |
| GET | `/api/group/{groupName}/members` | List members of a group |
| POST | `/api/group/{groupName}/add-user/{samAccountName}` | Add a user to a group |
| POST | `/api/group/{groupName}/remove-user/{samAccountName}` | Remove a user from a group |

---

## 🔒 Security

- Secure LDAP login via LDAPS or StartTLS
- Proper error handling for LDAP operations
- No sensitive credentials stored in the Git repository

---

## 💛 License

MIT License.

> Developed with 💛 to automate Active Directory management.
