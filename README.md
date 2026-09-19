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
   git clone https://github.com/mamadoumadioudiallo/Diallo9999.git

2. Copier les fichiers de config
   cp includes/db.example.php includes/db.php
   cp includes/mailer.example.php includes/mailer.php

3. Modifier includes/db.php avec vos identifiants

4. Importer la base de données
   mysql -u root -p fleet_iot_db < database/fleet_iot_db.sql

5. Lancer sur XAMPP → http://localhost/fleet_iot

##  Structure
fleet_iot/
├── admin/          # Pages administrateur
├── superviseur/    # Pages superviseur  
├── conducteur/     # Pages conducteur
├── api/            # Endpoints IoT REST
├── includes/       # Config DB, auth, fonctions
├── rapports/       # Export PDF/CSV
├── simulation/     # Simulateur IoT
└── database/       # Scripts SQL

## Auteur
Mamadou Madiou Diallo — mamadoumadioudiallo@github — dmamadoumadiou61@gmail.com

##  Licence
MIT License
