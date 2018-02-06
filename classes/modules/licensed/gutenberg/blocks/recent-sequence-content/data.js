/**
 * Returns a Promise with the latest posts or an error on failure.
 *
 * @param   {Number} postsToShow       Number of posts to display.
 *
 * @returns {wp.api.collections.Posts} Returns a Promise with the latest posts.
 */
export function getLatestSequenceMembers( postsToShow = 5 ) {
	const sequenceCollection = new wp.api.collections.Posts(); // TODO: Load the sequence posts from collection(s)

	const members = sequenceCollection.fetch( {
		data: {
			per_page: postsToShow,
		},
	} );

	return members;
}

