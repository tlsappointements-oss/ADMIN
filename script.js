// --- FIREBASE CONFIGURATION ---
const firebaseConfig = {
  apiKey: "YOUR_API_KEY",
  authDomain: "YOUR_PROJECT.firebaseapp.com",
  projectId: "YOUR_PROJECT_ID",
  storageBucket: "YOUR_PROJECT.appspot.com",
  messagingSenderId: "YOUR_SENDER_ID",
  appId: "YOUR_APP_ID"
};

// Initialize Firebase (Only if Firebase script is loaded)
if (typeof firebase !== 'undefined') {
    firebase.initializeApp(firebaseConfig);
}

// --- REDIRECTION LOGIC ---
const loginBtn = document.getElementById('loginBtn');

if (loginBtn) {
    loginBtn.addEventListener('click', () => {
        const email = document.getElementById('email').value;
        const pass = document.getElementById('password').value;

        // Firebase Auth Logic
        firebase.auth().signInWithEmailAndPassword(email, pass)
            .then((userCredential) => {
                // Redirect on success
                window.location.href = "admin.html";
            })
            .catch((error) => {
                alert("Error: " + error.message);
            });
    });
}

// Add Car Logic for Admin
function saveCar() {
    const model = document.getElementById('modelName').value;
    alert("Car " + model + " saved to database!");
    // Here you would use firebase.firestore() to save the data
}