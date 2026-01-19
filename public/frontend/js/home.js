// Wait for the window to load and then hide the preloader
window.onload = function() {
    // Add the 'loaded' class to the body when page is loaded
    document.body.classList.add('loaded');
  }




      // Smooth scroll to section (for example, to a section where users upload or view reports)
      document.getElementById('uploadBtn').addEventListener('click', function(event) {
          event.preventDefault();
          // Smooth scroll to an ID on the page (example: "uploadSection")
          document.querySelector('#uploadSection').scrollIntoView({
              behavior: 'smooth'
          });
      });

      document.getElementById('viewReportsBtn').addEventListener('click', function(event) {
          event.preventDefault();
          // Smooth scroll to the reports section (example: "reportsSection")
          document.querySelector('#reportsSection').scrollIntoView({
              behavior: 'smooth'
          });
      });

      // Add a hover effect to the buttons for a more dynamic interaction
      document.querySelectorAll('.hero-buttons .btn').forEach(button => {
          button.addEventListener('mouseover', () => {
              button.style.transform = 'scale(1.05)';
              button.style.transition = 'transform 0.3s ease';
          });

          button.addEventListener('mouseout', () => {
              button.style.transform = 'scale(1)';
          });
      });



    // Get the button
    const scrollUpBtn = document.getElementById('scrollUpBtn');

    // When the user scrolls down 100px from the top of the document, show the button
    window.onscroll = function() {
      if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
        scrollUpBtn.classList.add('show');
      } else {
        scrollUpBtn.classList.remove('show');
      }
    };

    // When the button is clicked, scroll to the top of the page
    scrollUpBtn.onclick = function() {
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    };

