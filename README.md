# FleetIoT : Système de Gestion de Flotte IoT
### Projet Simandou   Corridor Minier de Guinée
Application web de gestion de flotte (minier) IoT développée pour le corridor minier Simandou  en République de Guinée. Ce système permet le suivi GPS en temps réel, le suivi de niveau de carburant, la pression des pneus, l’ etat du moteur, la charge de nette des engins roulant, la gestion des missions,  le pointage et la formation des conducteurs, et les rapports investisseurs et des rapports des KPIs.
##  Fonctionnalités
-  Carte GPS temps réel (Leaflet.js)
-  Alertes automatiques (vitesse, température, TPMS, surcharge)
-  Gestion des missions avec notification conducteur
-  Pointage self-service géolocalisé
-  Simandou Academy (formations en ligne + quiz + attestation PDF)
-  Rapports investisseurs PDF par email
-  3 rôles : Admin, Superviseur, Conducteur

##  Stack technique
- **Backend** : PHP 8 + PDO
- **Base de données** : MySQL / MariaDB
- **Frontend** : HTML5, CSS3, JavaScript
- **Cartographie** : Leaflet.js
- **Graphiques** : Chart.js
- **PDF** : FPDF
- **Email** : PHPMailer

##  Installation
1. Cloner le repository
   git clone Gestion de flotte : https://github.com/mamadoumadioudiallo/Systme-Internet-of-Things-pour-la-gestion-intelligente-des-flottes-de-transport-minier.git

2. Copier les fichiers de config
   cp includes/db.example.php includes/db.php
   cp includes/mailer.example.php includes/mailer.php

3. Modifier includes/db.php avec vos identifiants

4. Importer la base de données
   mysql -u root -p fleet_iot_db < database/fleet_iot_db.sql

5. Lancer sur XAMPP → http://localhost/fleet_iot

##  Structure
## 📁 Structure du projet

```
FleetIoT-Simandou-2040/
├── 📂 admin/                    # Pages administrateur
├── 📂 api/                      # Endpoints IoT REST
├── 📂 assets/                   # CSS, JS, images
├── 📂 backups/                  # Sauvegardes base de données
├── 📂 conducteur/               # Pages conducteur
├── 📂 includes/                 # Config DB, auth, fonctions
├── 📂 lib/                      # Librairies (FPDF, PHPMailer)
├── 📂 rapports/                 # Export PDF/CSV
├── 📂 scripts/                  # Scripts utilitaires
├── 📂 simulation/               # Simulateur IoT
├── 📂 superviseur/              # Pages superviseur
├── 📂 vendor/                   # Dépendances Composer
├── 📂 websocket/                # Temps réel WebSocket
├── 📄 connexion.php             # Authentification
├── 📄 deconnexion.php           # Déconnexion
├── 📄 index.php                 # Page d'accueil
├── 📄 inscription.php           # Inscription utilisateur
├── 📄 mot_de_passe_oublie.php   # Récupération mot de passe
├── 📄 reinitialiser_mot_de_passe.php  # Réinitialisation
├── 📄 verification_2fa.php      # Double authentification
├── 📄 composer.json             # Dépendances PHP
└── 📄 README.md                 # Documentation
```

##  Architecture du système

```mermaid
flowchart TD
    subgraph ROLES[" Interfaces Utilisateurs"]
        A[" ADMIN"]
        B[" SUPERVISEUR"]
        C[" CONDUCTEUR"]
    end

    subgraph APP[" Application Web PHP"]
        D[" GPS\nLeaflet.js"]
        E[" Missions\nNotifications"]
        F[" Formations\nQuiz"]
        G[" Pointage\nIoT"]
        H[" Rapports\nPDF & Email"]
        I[" Alertes\nAutomatiques"]
    end

    subgraph API[" API REST PHP"]
        J["api/ingest.php ──► api/telemetrie.php"]
    end

    subgraph DB[" Base de Données MySQL"]
        K["15+ tables · PDO · MariaDB"]
    end

    subgraph IOT[" Boîtiers IoT 4G/GPS"]
        L["GPS · Moteur · Carburant · TPMS · Chargement"]
    end

    ROLES --> APP
    APP --> API
    API --> DB
    IOT -->|"Toutes les 5s"| API
```

##  Flux de données IoT

```
Boîtier GPS/IoT
      │
      │ HTTP POST (JSON) toutes les 5s
      ▼
api/ingest.php
      │
      ├──► Table `telemetrie` (MySQL)
      │
      ├──► evaluerAlertesVehicule()
      │         │
      │         └──► Table `alertes`
      │
      ▼
api/telemetrie.php
      │
      │ Polling toutes les 5s
      ▼
Dashboard (Leaflet.js + Chart.js)
      │
      ├──► Carte GPS mise à jour
      ├──► KPI recalculés
      └──► Alertes affichées
```
##  Diagramme de cas d'utilisation UML

```mermaid
flowchart LR
    Visiteur([" Visiteur"])
    Conducteur([" Conducteur"])
    Superviseur([" Superviseur"])
    Admin([" Administrateur"])

    subgraph SYS[" Système FleetIoT — Simandou 2040"]

        subgraph VIS["Accès public"]
            UC1(["Consulter page accueil"])
            UC2(["Utiliser formulaire contact"])
            UC3(["Accéder à la connexion"])
        end

        subgraph COND["Espace Conducteur"]
            UC4(["Se connecter"])
            UC5(["Consulter ses missions"])
            UC6(["Pointer arrivée/pause/départ"])
            UC7(["Suivre progression GPS"])
            UC8(["Suivre ses formations"])
            UC9(["Passer un quiz"])
            UC10(["Télécharger attestation PDF"])
        end

        subgraph SUP["Espace Superviseur"]
            UC11(["Consulter tableau de bord"])
            UC12(["Visualiser véhicules sur carte"])
            UC13(["Consulter et traiter alertes"])
            UC14(["Gérer ses missions"])
            UC15(["Voir pointage conducteurs"])
            UC16(["Suivre formations conducteurs"])
        end

        subgraph ADM["Espace Administrateur"]
            UC17(["Gérer véhicules et conducteurs"])
            UC18(["Gérer comptes superviseurs"])
            UC19(["Contrôler simulateur IoT"])
            UC20(["Générer rapports PDF/CSV"])
            UC21(["Envoyer rapport investisseur"])
            UC22(["Paramétrer seuils alertes"])
            UC23(["Créer formations Academy"])
            UC24(["Consulter journal audit"])
        end

    end

    Visiteur --> UC1
    Visiteur --> UC2
    Visiteur --> UC3

    Conducteur --> UC4
    Conducteur --> UC5
    Conducteur --> UC6
    Conducteur --> UC7
    Conducteur --> UC8
    Conducteur --> UC9
    Conducteur --> UC10

    Superviseur --> UC11
    Superviseur --> UC12
    Superviseur --> UC13
    Superviseur --> UC14
    Superviseur --> UC15
    Superviseur --> UC16

    Admin --> UC17
    Admin --> UC18
    Admin --> UC19
    Admin --> UC20
    Admin --> UC21
    Admin --> UC22
    Admin --> UC23
    Admin --> UC24

    Superviseur -.->|include| Conducteur
    Admin -.->|extend| Superviseur
```

##  Flux d'une mission

```mermaid
graph TD
    A[Admin/Sup crée une mission] --> B[vue_conducteur = 0]
    B --> C[Notification conducteur ]
    C --> D{Conducteur consulte}
    D --> E[Marque comme lu]
    E --> F[vue_conducteur = 1]
    F --> G[ Lu par conducteur visible côté Admin/Sup]
    D --> H[Suivi progression GPS ]
    H --> I[Mission terminée]
```

## Auteur
Mamadou Madiou Diallo — mamadoumadioudiallo@github — dmamadoumadiou61@gmail.com

##  Licence
MIT License
