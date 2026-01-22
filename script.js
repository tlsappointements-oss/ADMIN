// --- ADMIN SECURITY ---
function checkAuth() {
    // Check if user is logged into Firebase
    firebase.auth().onAuthStateChanged((user) => {
        if (!user) {
            // If not logged in, redirect to login page
            window.location.href = "login.html";
        }
    });
}

function logout() {
    firebase.auth().signOut().then(() => {
        window.location.href = "login.html";
    });
}

// --- ADMIN UI MANIPULATION ---
function toggleSection(section) {
    alert("Switching to " + section + " management...");
    // In a real app, you would hide/show divs here
}

function openAddCarModal() {
    const model = prompt("Enter Vehicle Model Name:");
    const price = prompt("Enter Vehicle Price:");
    if(model && price) {
        alert(model + " has been added to the database.");
        // Logic to push to Firebase Firestore would go here
    }
}