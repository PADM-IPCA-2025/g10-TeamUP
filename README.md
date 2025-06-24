# TeamUP

**TeamUP** is a app built as part of a Master’s project in Software Engineering.

- **Backend:** Laravel microservices 
- **Web frontend:** React  
- **Mobile app:** Android (Kotlin + Jetpack Compose)  
- **Deployment:** Docker Compose

---

## 🚀 Start the Server

1. Go to the **services** folder:
   ```bash
   cd services
   ```
2. Start the containers in detached mode:
   ```bash
   docker compose up -d
   ```
3. Run the seed script (`database`), which will execute:
   ```bash
   docker compose exec users-main-app       php artisan migrate --seed
   docker compose exec event_manager-app    php artisan migrate --seed
   docker compose exec chat-app             php artisan migrate --seed
   docker compose exec rating-app           php artisan migrate --seed
   docker compose exec achievements-app     php artisan migrate --seed
   ```

## 🌐 Web Frontend 
> **Note:** The SSL-enabled Web version is on the **`WEB`** branch.
1. Go to the **FrontEnd/WEB** folder:
   ```bash
   cd FrontEnd/WEB
   ```
2. Start the containers:
   ```bash
   docker compose up -d
   ```

3. Access the app in your browser:  
   [http://localhost:3000/](http://localhost:3000/)

## 📱 Mobile Frontend

1. Open the **FrontEnd/Mobile** folder in Android Studio.

## 🧪 Test Account

Use the seeded accounts to log in and test:
- **Email:** `teste1@gmail.com`  
- **Password:** `password`

-  **Email:** `admin@example.com`  
- **Password:** `admin`
