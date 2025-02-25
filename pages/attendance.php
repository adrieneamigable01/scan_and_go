<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Detection and Recognition with face-api.js</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; flex-direction: column; }
        #container { position: relative; }
        #video { width: 640px; height: 480px; }
        #canvas { position: absolute; top: 0; left: 0; }
        #verificationStatus { margin-top: 10px; font-size: 1.2em; }
        #message { font-size: 1.1em; font-weight: bold; margin-top: 10px; }
        button { margin-top: 10px; padding: 10px 20px; font-size: 1em; cursor: pointer; }
    </style>
</head>
<body>
    <div id="container">
        <div style="width:80%">
            <video id="video" autoplay></video>
            <canvas id="canvas"></canvas>
            <div id="verificationStatus">Loading Face Detection...</div>
            <!-- <button id="capture">Capture Face</button> -->
            <!-- <button id="verify">Verify Face</button> -->
            <div id="message"></div>
        </div>
        <div style="width:30%">
            <div id="scanned_message">

            </div>
        </div>
    </div>

    <!-- Load face-api.js library -->
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>

    <!-- jQuery for AJAX requests -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Custom script to handle video and face detection -->
    <script>
        // Global variables for model and face descriptor storage
        let knownFaceDescriptors = [];
        let verificationMesages = [];
        let capturedFaceDescriptor = null;
        let verifiedFaces = new Map();

        // DOM elements
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const ctx = canvas.getContext('2d');
        const verificationStatus = document.getElementById('verificationStatus');
        const messageElement = document.getElementById('message');
        
        // Load face-api.js models
        async function loadModel() {
            // Load models from a folder on your server (or use a CDN)
            
            await faceapi.nets.ssdMobilenetv1.loadFromUri('/scan/models');
            await faceapi.nets.faceRecognitionNet.loadFromUri('/scan/models');
            await faceapi.nets.faceLandmark68Net.loadFromUri('/scan/models');
            verificationStatus.textContent = 'Face API Models Loaded!';
            startVideoStream();
            loadScannedMessage();
        }

        // Start the webcam video stream
        async function startVideoStream() {
            const stream = await navigator.mediaDevices.getUserMedia({ video: {} });
            video.srcObject = stream;
            video.width = 640;
            video.height = 480;
            video.onloadedmetadata = () => {
                canvas.width = video.width;
                canvas.height = video.height;
                video.play();
                detectFaces();
            };
        }

        // Detect faces and extract face descriptors from video
        async function detectFaces() {
            setInterval(async () => {
                if (video && faceapi) {
                    const detections = await faceapi.detectAllFaces(video)
                        .withFaceLandmarks()
                        .withFaceDescriptors();

                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    if (detections.length > 0) {
                        // Draw bounding boxes around faces
                        detections.forEach(det => {
                            const { x, y, width, height } = det.detection.box;
                            ctx.beginPath();
                            ctx.rect(x, y, width, height);
                            ctx.lineWidth = 2;
                            ctx.strokeStyle = 'green';
                            ctx.stroke();
                        });

                        // Capture the face descriptor of the first detected face
                        const faceDescriptor = detections[0].descriptor;
                        capturedFaceDescriptor = faceDescriptor; // Store the face descriptor
                        messageElement.textContent = 'Face detected and descriptor captured!';
                        verificationStatus.textContent = 'Face Detected!';
                      
                       

                        const isFaceAlreadyCaptured = checkIfFaceAlreadyCaptured(capturedFaceDescriptor);
                
                        if (isFaceAlreadyCaptured) {
                            messageElement.textContent = 'This face has already been scanned.';
                            
                            console.log('This face has already been scanned.');
                        } else {
                            verifyFace(capturedFaceDescriptor);
                        }
                        

                    } else {
                        verificationStatus.textContent = 'No Face Detected.';
                    }
                }
            }, 100); // Update every 100ms
        }
        function checkIfFaceAlreadyCaptured(faceDescriptor) {
            return knownFaceDescriptors.some(knownFace => compareFaceDescriptors(faceDescriptor, knownFace) > 0.9); // Threshold of 0.9 for a match
        }
        async function verifyFace(faceDescriptor) {
            knownFaceDescriptors.push(faceDescriptor); // Store the new face descriptor in the known faces list
            try {
                const response = await $.ajax({
                    type: 'POST',
                    url: 'verifyFace.php',
                    data: { descriptor: JSON.stringify(faceDescriptor) },
                    dataType:'json',
                    success: function (data) {
                        if (!data.isError) {
                            messageElement.textContent = 'Face matched successfully!';
                            verificationMesages.push({
                                'message':data.message,
                                'user':data.username
                            });
                        } else {
                            messageElement.textContent = 'Face not found!';
                        }
                    }
                });
            } catch (error) {
                verifiedFaces.set(faceDescriptor, false);
                messageElement.textContent = 'Error verifying face descriptor.';
            }
        }

        // Capture the face and save its descriptor
        async function captureFace() {
            if (capturedFaceDescriptor) {
                messageElement.textContent = 'Face captured successfully!';
                saveFaceDescriptor(capturedFaceDescriptor);
            } else {
                messageElement.textContent = 'No face detected to capture.';
            }
        }

        // Save the captured face descriptor to the server
        function saveFaceDescriptor(faceDescriptor) {
            $.ajax({
                type: 'POST',
                url: 'saveFace.php', // Server-side script to save the descriptor
                data: { descriptor: JSON.stringify(Array.from(faceDescriptor)) }, // Convert Float32Array to array
                success: function(response) {
                    $('#message').text(response.message); // Display response from server
                  
                    
                },
                error: function() {
                    $('#message').text('Error saving face descriptor.');    
                }
            });
        }

        function compareFaceDescriptors(descriptor1, descriptor2) {
            // Convert Float32Array to normal arrays (if they are Float32Arrays)
            const arr1 = Array.from(descriptor1);
            const arr2 = Array.from(descriptor2);

            const dotProduct = arr1.reduce((sum, value, i) => sum + value * arr2[i], 0);
            const magnitudeA = Math.sqrt(arr1.reduce((sum, value) => sum + value * value, 0));
            const magnitudeB = Math.sqrt(arr2.reduce((sum, value) => sum + value * value, 0));
            
            return dotProduct / (magnitudeA * magnitudeB);
        }

        // Cosine similarity to compare two face descriptors
        function cosineSimilarity(a, b) {
            const dotProduct = a.reduce((sum, value, i) => sum + value * b[i], 0);
            const magnitudeA = Math.sqrt(a.reduce((sum, value) => sum + value * value, 0));
            const magnitudeB = Math.sqrt(b.reduce((sum, value) => sum + value * value, 0));
            return dotProduct / (magnitudeA * magnitudeB);
        }

        function loadScannedMessage(){
            $("#scanned_message").empty();
            console.log(verificationMesages.length)
            $.each(verificationMesages,function(k,v){
                console.log(v.user)
                $("#scanned_message").append(
                    $("<div>").text(`${v.user} : ${v.message}`),
                );
            })
            setTimeout(() => {
                loadScannedMessage()
            }, 2000);
        }

        // Button click event to verify face
        $('#verify').on('click', function() {
            if (capturedFaceDescriptor) {
                // Compare the captured face with known descriptors
                const match = compareFaceDescriptors(capturedFaceDescriptor, knownFaceDescriptors);
                if (match) {
                    messageElement.textContent = 'Face matched successfully!';
                } else {
                    messageElement.textContent = 'Face did not match.';
                }
            } else {
                messageElement.textContent = 'No captured face to verify.';
            }
        });

        // Button click event to capture the face
        $('#capture').on('click', function() {
            captureFace();
        });

        // Load the models and start the video stream
        loadModel();
    </script>
</body>
</html>
