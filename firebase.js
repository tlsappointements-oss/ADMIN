import { initializeApp } from "https://www.gstatic.com/firebasejs/10.7.1/firebase-app.js";
import { getAuth } from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";
import { getFirestore } from "https://www.gstatic.com/firebasejs/10.7.1/firebase-firestore.js";

const firebaseConfig = {
  apiKey: "AIzaSyDnp4fC2_cEw04ydtWOwYgVzRUsqScufFs",
  authDomain: "cars-website-558c0.firebaseapp.com",
  projectId: "cars-website-558c0",
  storageBucket: "cars-website-558c0.firebasestorage.app",
  messagingSenderId: "142475379783",
  appId: "1:142475379783:web:2849d07fb6eb8da4715d62",
  measurementId: "G-53ZQY6XJJR"
};

// Initialize Firebase
const app = initializeApp(firebaseConfig);

// Export for maintenance.html
export const auth = getAuth(app);
export const db = getFirestore(app);