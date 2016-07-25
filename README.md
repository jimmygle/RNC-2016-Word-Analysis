# 2016 RNC World Analysis

For convenience, I've added a text file with the current output of a list of words sorted by frequency of use. There's a lot of cleanup that needs to be done.

This script has functions in it to scrape C-SPAN's closed captioning of all the speakers during the 2016 RNC. It also has some rudimentary word analysis functionality.

I was curious to see a word cloud and some sentiment analysis of the convention and didn't seem to find anything out there that provided that.

The actual functions that do the scraping aren't being called. I also provided all the photos of the speakers for future use when I actually make something more compelling with the data.

C-SPAN's videos of the speeches can be found here: http://www.c-span.org/convention/

## Requirements

* PHP 5.3+ 
* Composer
* Probably a *nix file system (haven't tested on Windows)

## Setup & Use

1. `composer install`
2. `php index.php`

