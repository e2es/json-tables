let tooltipDiv = null; // Placeholder for the tooltip div
tooltipDiv = document.createElement('div');
tooltipDiv.classList.add('custom-tooltip');
document.body.appendChild(tooltipDiv);
function addTooltip(link) {

    let isMouseOver = false; // Flag to check if mouse is still over the link
    link.addEventListener('mouseover', function(e) {

        e.preventDefault();
        isMouseOver = true; // Set flag to true

        // Extract permalink from the href attribute of the hovered link
        let permalink = this.getAttribute('href');

        // Prepare the data to get the post ID
        let data = {
            'action': 'get_post_id_from_permalink',
            'permalink': permalink
        };

        // Prepare the url and parameters
        let url = tooltip_data.ajaxurl;
        let params = new URLSearchParams(data).toString();

        // Create the fetch request to get the post ID
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: params
        })
            .then(response => response.json())
            .then(post_id => {
                // Now we have the post ID, we can get the post data
                return fetch(tooltip_data.ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        'action': 'tooltip_ajax_handler',
                        'post_id': post_id
                    }).toString()
                })
            })
            .then(response => response.json())
            .then(response => {
                // Check if the mouse is still over the link
                if(isMouseOver && response && "title" in response) {
                    // Create the tooltip content and display the tooltip
                    tooltipDiv.classList.add('active');
                    if(response.featured_image_url){

                        tooltipDiv.innerHTML = `<div class="tooltip-image"><img src="${response.featured_image_url}" alt="${response.title}"></div><div class="tooltip-content"><div class="tooltip-title">${response.title}</div>${response.excerpt}</div>`;

                    } else {

                        tooltipDiv.innerHTML = `<div class="tooltip-content"><div class="tooltip-title">${response.title}</div>${response.excerpt}</div>`;
                    }

                    tooltipDiv.style.left = `${e.pageX}px`;
                    if(document.body.clientWidth < (e.pageX+410)) { tooltipDiv.style.left = `${e.pageX - 400}px`;}
                    tooltipDiv.style.top = `${e.pageY}px`;
                }
            })
            .catch(error => console.error('Error:', error));
    });

    // Hide the tooltip when the mouse leaves the link
    link.addEventListener('mouseout', function() {
        isMouseOver = false; // Set flag to false
        if (tooltipDiv) {
            tooltipDiv.classList.remove('active');
        }
    });
}

document.addEventListener('DOMContentLoaded', (event) => {

    // Add event listener to all 'a' tags
    document.querySelectorAll('.content-bg a:not(.btn)').forEach((link) => {
        addTooltip(link);
    });


});
