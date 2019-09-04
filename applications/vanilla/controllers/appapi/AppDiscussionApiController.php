<?php
/**
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license GPLv2
 */

use Garden\Schema\Schema;
use Garden\Web\Data;
use Garden\Web\Exception\NotFoundException;
use Garden\Web\Exception\ServerException;
use Vanilla\DateFilterSchema;
use Vanilla\ApiUtils;

/**
 * API Controller for the `/appdiscussion` resource.
 */
class AppDiscussionApiController extends AbstractApiController {

    /** @var DiscussionModel */
    private $discussionModel;

    /** @var Schema */
    private $discussionSchema;

    /** @var Schema */
    private $discussionPostSchema;

    /** @var Schema */
    private $idParamSchema;

    /** @var UserModel */
    private $userModel;

    /**
     * DiscussionsApiController constructor.
     *
     * @param DiscussionModel $discussionModel
     * @param UserModel $userModel
     */
    public function __construct(
        DiscussionModel $discussionModel,
        UserModel $userModel
    ) {
        $this->discussionModel = $discussionModel;
        $this->userModel = $userModel;
    }

    /**
     * Get the full discussion schema.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function discussionSchema($type = '') {
        if ($this->discussionSchema === null) {
            $this->discussionSchema = $this->schema($this->fullSchema(), 'Discussion');
        }
        return $this->schema($this->discussionSchema, $type);
    }

    /**
     * Get a schema instance comprised of all available discussion fields.
     *
     * @return Schema Returns a schema object.
     */
    protected function fullSchema() {
        return Schema::parse([
            'discussionID:i' => 'The ID of the discussion.',
            'type:s|n' => [
                //'enum' => [] // Let's find a way to fill that properly.
                'description' => 'The type of this discussion if any.',
            ],
            'name:s' => 'The title of the discussion.',
            'body:s' => 'The body of the discussion.',
            'categoryID:i' => 'The category the discussion is in.',
            'dateInserted:dt' => 'When the discussion was created.',
            'dateUpdated:dt|n' => 'When the discussion was last updated.',
            'insertUserID:i' => 'The user that created the discussion.',
            'insertUser?' => $this->getUserFragmentSchema(),
            'lastUser?' => $this->getUserFragmentSchema(),
            'pinned:b?' => 'Whether or not the discussion has been pinned.',
            'pinLocation:s|n' => [
                'enum' => ['category', 'recent'],
                'description' => 'The location for the discussion, if pinned. "category" are pinned to their own category. "recent" are pinned to the recent discussions list, as well as their own category.'
            ],
            'closed:b' => 'Whether the discussion is closed or open.',
            'sink:b' => 'Whether or not the discussion has been sunk.',
            'countComments:i' => 'The number of comments on the discussion.',
            'countViews:i' => 'The number of views on the discussion.',
            'score:i|n' => 'Total points associated with this post.',
            'url:s?' => 'The full URL to the discussion.',
            'lastPost?' => $this->getPostFragmentSchema(),
            'bookmarked:b' => 'Whether or not the discussion is bookmarked by the current user.',
            'unread:b' => 'Whether or not the discussion should have an unread indicator.',
            'countUnread:i?' => 'The number of unread comments.',
        ]);
    }

    /**
     * Normalize a database record to match the Schema definition.
     *
     * @param array $dbRecord Database record.
     * @param array|bool $expand
     * @return array Return a Schema record.
     */
    public function normalizeOutput(array $dbRecord, $expand = []) {
        $dbRecord['Announce'] = (bool)$dbRecord['Announce'];
        $dbRecord['Bookmarked'] = (bool)$dbRecord['Bookmarked'];
        $dbRecord['Url'] = discussionUrl($dbRecord);
        $this->formatField($dbRecord, 'Body', $dbRecord['Format']);

        if (!is_array($dbRecord['Attributes'])) {
            $attributes = dbdecode($dbRecord['Attributes']);
            $dbRecord['Attributes'] = is_array($attributes) ? $attributes : [];
        }

        if ($this->getSession()->User) {
            $dbRecord['unread'] = $dbRecord['CountUnreadComments'] !== 0
                && ($dbRecord['CountUnreadComments'] !== true || dateCompare(val('DateFirstVisit', $this->getSession()->User), $dbRecord['DateInserted']) <= 0);
            if ($dbRecord['CountUnreadComments'] !== true && $dbRecord['CountUnreadComments'] > 0) {
                $dbRecord['countUnread'] = $dbRecord['CountUnreadComments'];
            }
        } else {
            $dbRecord['unread'] = false;
        }

        if ($this->isExpandField('lastPost', $expand)) {
            $lastPost = [
                'discussionID' => $dbRecord['DiscussionID'],
                'dateInserted' => $dbRecord['DateLastComment'],
                'insertUser' => $dbRecord['LastUser']
            ];
            if ($dbRecord['LastCommentID']) {
                $lastPost['CommentID'] = $dbRecord['LastCommentID'];
                $lastPost['name'] = sprintft('Re: %s', $dbRecord['Name']);
                $lastPost['url'] = commentUrl($lastPost, true);
            } else {
                $lastPost['name'] = $dbRecord['Name'];
                $lastPost['url'] = $dbRecord['Url'];
            }

            $dbRecord['lastPost'] = $lastPost;
        }

        $schemaRecord = ApiUtils::convertOutputKeys($dbRecord);
        $schemaRecord['type'] = isset($schemaRecord['type']) ? lcfirst($schemaRecord['type']) : null;

        // Allow addons to hook into the normalization process.
        $options = ['expand' => $expand];
        $result = $this->getEventManager()->fireFilter('discussionsApiController_normalizeOutput', $schemaRecord, $this, $options);

        return $result;
    }

    /**
     * List discussions.
     *
     * @param array $query The query string.
     * @return Data
     */
    public function index(array $query) {
        $this->permission();

        $in = $this->schema([
            'categoryID:i?' => [
                'description' => 'Filter by a category.',
                'x-filter' => [
                    'field' => 'd.CategoryID'
                ],
            ],
            'dateInserted?' => new DateFilterSchema([
                'description' => 'When the discussion was created.',
                'x-filter' => [
                    'field' => 'd.DateInserted',
                    'processor' => [DateFilterSchema::class, 'dateFilterField'],
                ],
            ]),
            'dateUpdated?' => new DateFilterSchema([
                'description' => 'When the discussion was updated.',
                'x-filter' => [
                    'field' => 'd.DateUpdated',
                    'processor' => [DateFilterSchema::class, 'dateFilterField'],
                ],
            ]),
            'type:s?' => [
                'description' => 'Filter by discussion type.',
                'x-filter' => [
                    'field' => 'd.Type'
                ],
            ],
            'followed:b' => [
                'default' => false,
                'description' => 'Only fetch discussions from followed categories. Pinned discussions are mixed in.'
            ],
            'pinned:b?' => 'Whether or not to include pinned discussions. If true, only return pinned discussions. Cannot be used with the pinOrder parameter.',
            'pinOrder:s?' => [
                'default' => 'first',
                'description' => 'If including pinned posts, in what order should they be integrated? When "first", discussions pinned to a specific category will only be affected if the discussion\'s category is passed as the categoryID parameter. Cannot be used with the pinned parameter.',
                'enum' => ['first', 'mixed'],
            ],
            'page:i?' => [
                'description' => 'Page number. See [Pagination](https://docs.vanillaforums.com/apiv2/#pagination).',
                'default' => 1,
                'minimum' => 1,
                'maximum' => $this->discussionModel->getMaxPages()
            ],
            'limit:i?' => [
                'description' => 'Desired number of items per page.',
                'default' => $this->discussionModel->getDefaultLimit(),
                'minimum' => 1,
                'maximum' => 100
            ],
            'insertUserID:i?' => [
                'description' => 'Filter by author.',
                'x-filter' => [
                    'field' => 'd.InsertUserID',
                ],
            ],
            'expand?' => ApiUtils::getExpandDefinition(['insertUser', 'lastUser', 'lastPost'])
        ], ['DiscussionIndex', 'in'])->setDescription('List discussions.');
        $out = $this->schema([':a' => $this->discussionSchema()], 'out');

        $query = $this->filterValues($query);
        $query = $in->validate($query);

        $where = ApiUtils::queryToFilters($in, $query);

        list($offset, $limit) = offsetLimit("p{$query['page']}", $query['limit']);

        if (array_key_exists('categoryID', $where)) {
            $this->discussionModel->categoryPermission('Vanilla.Discussions.View', $where['categoryID']);
        }

        // Allow addons to update the where clause.
        $where = $this->getEventManager()->fireFilter('discussionsApiController_indexFilters', $where, $this, $in, $query);

        if ($query['followed']) {
            $where['Followed'] = true;
            $query['pinOrder'] = 'mixed';
        }

        $pinned = array_key_exists('pinned', $query) ? $query['pinned'] : null;
        if ($pinned === true) {
            $announceWhere = array_merge($where, ['d.Announce >' => '0']);
            $rows = $this->discussionModel->getAnnouncements($announceWhere, $offset, $limit)->resultArray();
        } else {
            $pinOrder = array_key_exists('pinOrder', $query) ? $query['pinOrder'] : null;
            if ($pinOrder == 'first') {
                $announcements = $this->discussionModel->getAnnouncements($where, $offset, $limit)->resultArray();
                $discussions = $this->discussionModel->getWhereRecent($where, $limit, $offset, false)->resultArray();
                $rows = array_merge($announcements, $discussions);
            } else {
                $where['Announce'] = 'all';
                $rows = $this->discussionModel->getWhereRecent($where, $limit, $offset, false)->resultArray();
            }
        }

        // Expand associated rows.
        $this->userModel->expandUsers(
            $rows,
            $this->resolveExpandFields($query, ['insertUser' => 'InsertUserID', 'lastUser' => 'LastUserID']),
            ['expand' => $query['expand']]
        );

        foreach ($rows as &$currentRow) {
            $currentRow = $this->normalizeOutput($currentRow, $query['expand']);
        }

        $result = $out->validate($rows, true);

        // Allow addons to modify the result.
        $result = $this->getEventManager()->fireFilter('discussionsApiController_indexOutput', $result, $this, $in, $query, $rows);

        $whereCount = count($where);
        $isWhereOptimized = (isset($where['d.CategoryID']) && ($whereCount === 1 || ($whereCount === 2 && isset($where['Announce']))));
        if ($whereCount === 0 || $isWhereOptimized) {
            $paging = ApiUtils::numberedPagerInfo($this->discussionModel->getCount($where), '/api/v2/discussions', $query, $in);
        } else {
            $paging = ApiUtils::morePagerInfo($rows, '/api/v2/discussions', $query, $in);
        }

        return new Data($result, ['paging' => $paging]);
    }
}