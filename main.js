// 1. Updated Configuration with free Google STUN servers
const configuration = {
  iceServers: [
    {
      urls: [
        'stun:stun.l.google.com:19302',
        'stun:stun1.l.google.com:19302',
        'stun:stun2.l.google.com:19302',
        'stun:stun3.l.google.com:19302',
        'stun:stun4.l.google.com:19302',
      ],
    },
  ],
  iceCandidatePoolSize: 10,
};

// 2. Initialize the Peer Connection
let peerConnection = new RTCPeerConnection(configuration);

// 3. IMPORTANT: You must listen for ICE candidates and send them to your signaling server (Firebase/Socket)
peerConnection.onicecandidate = (event) => {
  if (event.candidate) {
    // Replace 'sendToSignaling' with your actual function to save to Firebase/DB
    console.log("New ICE candidate:", event.candidate);
    sendCandidateToDatabase(event.candidate); 
  }
};

// 4. Handle incoming candidates from the other device
function onRemoteCandidateReceived(candidate) {
  peerConnection.addIceCandidate(new RTCIceCandidate(candidate))
    .catch(e => console.error("Error adding received candidate", e));
}