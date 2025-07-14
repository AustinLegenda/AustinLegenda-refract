function getCurrentTime() {
    let date = new Date();
    let dateString = date.toLocaleDateString();
    let timeString = 'report pulled at' + date.toLocaleTimeString();
    document.getElementById("date").innerHTML = dateString;
    document.getElementById("hour").innerHTML = timeString;
}
