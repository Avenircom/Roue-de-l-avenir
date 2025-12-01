🎡 Jeu Concours – Formulaire + Roue Avenir Communication
========================================================

Expérience complète de jeu concours en **2 étapes** :

1.  📝 Formulaire de participation
    
2.  🎠 Roue de tirage avec gestion des lots et anti-double participation
    

✨ 1. Fonctionnement général
---------------------------

Ce projet inclut :

*   📝 Un formulaire obligatoire avec validation
    
*   🎡 Une roue animée en Vue.js
    
*   🛡️ Une protection anti-rejoueurs basée sur **l’email + base de données**
    
*   📦 Une gestion automatique du stock des lots
    
*   📧 Un email envoyé après chaque tirage
    
*   🔄 Un parcours fluide : _formulaire → validation → roue_
    

🧭 2. Parcours utilisateur
--------------------------

### 🧩 Étape 1 – Formulaire

Champs :

*   Prénom
    
*   Nom
    
*   Email
    
*   Entreprise
    
*   Consentement obligatoire
    

Contrôles :

*   ✔️ Validation front
    
*   ✔️ Honeypot anti-robots
    
*   ✔️ Vérification email unique via save.php
    

Si tout est valide → redirection vers :roue.html?e=email

### 🎠 Étape 2 – Roue

Techniques utilisées :

*   Vue.js 2
    
*   CSS pour l’animation
    
*   Canvas pour les confettis
    
*   API status.php + spin.php
    

Fonctionnement :

*   🔍 Vérifie si l’utilisateur a déjà joué
    
*   🎯 Calcule un tirage pondéré (gagnants / consolation)
    
*   🔐 Empêche toute seconde participation
    
*   📧 Envoie le résultat par mail
    
*   🎉 Confettis en cas de gain
    

🗂️ 3. Architecture du projet
-----------------------------
/project
├── index.html        # Formulaire
├── roue.html         # Roue Vue.js
├── monstyle.css      # Styles
├── save.php          # Enregistrement participation
├── status.php        # Statut joueur (déjà joué ?)
├── spin.php          # Tirage + gestion des lots + mail
└── assets/           # Images, logos, JS complémentaires

🧱 4. Back-end – Rôle des scripts
---------------------------------

### 💾 save.php

*   Enregistre la participation
    
*   Vérifie si l’email existe déjà
    
*   Réponses :
    
    *   409 → email déjà participant
        
    *   422 → validation incorrecte
        
    *   200/201 → OK
        

### 🔍 status.php

Indique si l’utilisateur a déjà un tirage :

{
  "already_spun": true,
  "final_prize_text": "Echarpe",
  "final_win": 1,
  "r_value": 12.25
}


### 🎯 spin.php

*   Vérification des données
    
*   Verrouillage SQL (FOR UPDATE)
    
*   Attribution du lot + décrément des stocks
    
*   Enregistrement du tirage (spun\_at)
    
*   Envoi automatique d’un email
    
*   Retourne : 200, 208, 409
    

🗄️ 5. Structure base de données
--------------------------------

### 👤 Table users

*   email
    
*   prénom / nom / entreprise
    
*   prize\_text
    
*   prize\_win
    
*   spin\_r\_value
    
*   spun\_at (verrou de participation)
    

### 🎁 Table prizes

*   prize\_name
    
*   stock
    
*   is\_consolation
    
*   campaign\_id
    

🔧 6. Installation
------------------

1.  Déployer les fichiers sur un serveur PHP/MySQL
    
2.  Créer les tables users et prizes
    
3.  Configurer la connexion BDD dans :
    
    *   save.php
        
    *   status.php
        
    *   spin.php
        
4.  Vérifier la configuration mail
    
5.  Tester le parcours complet
    

🎨 7. Personnalisation
----------------------

*   Modifier les champs du formulaire
    
*   Ajuster les lots et les probabilités
    
*   Modifier les styles dans monstyle.css
    
*   Personnaliser les emails
    

⚠️ 8. Limites naturelles
------------------------

Le blocage se fait **par email**, ce qui empêche :

*   navigation privée
    
*   changement de navigateur
    
*   effacement du cache
    
*   changement d’appareil
    

⛔ Un utilisateur ne peut rejouer qu’en utilisant **une autre adresse email**.
