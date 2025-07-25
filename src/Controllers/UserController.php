<?php
// file: Controllers/UserController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Middleware\AuthMiddleware;
use App\Models\User;
use App\Models\Company;
use App\Models\Role;
use App\Utils\Email;
use App\Utils\Validator;
use RuntimeException;
use InvalidArgumentException;
use App\Services\SecurityService;

class UserController
{
    private AuthMiddleware $authMiddleware;
    private User $userModel;
    private Company $companyModel;
    private Role $roleModel;
    
    public function __construct()
    {
        $this->authMiddleware = new AuthMiddleware();
        $this->userModel = new User();
        $this->companyModel = new Company();
        $this->roleModel = new Role();
    }

    /**
     * Display paginated list of users
     * @param string $requestMethod
     * @param array $data
     * @throws RuntimeException
     */
    public function index(string $requestMethod, array $data): void
    {
        try {
            $this->authMiddleware->hasPermission('view_users');
            
            $page = isset($data['page']) ? max(1, intval($data['page'])) : 1;
            $settingsService = \App\Services\SettingsService::getInstance();
            $limit = $settingsService->getResultsPerPage();
            
            $results = $this->userModel->getAll(['is_deleted' => 0], $page, $limit);
            $users = $results['records'];
            $totalUsers = $results['total'];
            $totalPages = ceil($totalUsers / $limit);
            
            include BASE_PATH . '/../Views/Users/index.php';
        } catch (\Exception $e) {
            error_log("Exception in UserController::index: " . $e->getMessage());
            $_SESSION['error'] = 'An error occurred while fetching users.';
            header('Location: /dashboard');
            exit;
        }
    }

    /**
     * View user details
     * @param string $requestMethod
     * @param array $data
     * @throws RuntimeException
     */
    public function view(string $requestMethod, array $data): void
    {
        try {
            $this->authMiddleware->hasPermission('view_users');

            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new InvalidArgumentException('Invalid user ID');
            }

            $user = $this->userModel->findWithDetails($id);
            if (!$user || $user->is_deleted) {
                throw new InvalidArgumentException('User not found');
            }

            // Get user's roles and permissions
            $userRoleData = $this->userModel->getRolesAndPermissions($id);
            $user->roles = $userRoleData['roles'];
            $user->permissions = $userRoleData['permissions'];

            include BASE_PATH . '/../Views/Users/view.php';
        } catch (InvalidArgumentException $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /users');
            exit;
        } catch (\Exception $e) {
            error_log("Exception in UserController::view: " . $e->getMessage());
            $_SESSION['error'] = 'An error occurred while fetching user details.';
            header('Location: /users');
            exit;
        }
    }

    /**
     * View current user's profile
     * @param string $requestMethod
     * @param array $data
     * @throws RuntimeException
     */
    public function profile(string $requestMethod, array $data): void
    {
        try {
            // Check if user is logged in
            if (!isset($_SESSION['user']['profile']['id'])) {
                $_SESSION['error'] = 'You must be logged in to view your profile.';
                header('Location: /login');
                exit;
            }

            $userId = $_SESSION['user']['profile']['id'];

            $user = $this->userModel->findWithDetails($userId);
            if (!$user || $user->is_deleted) {
                $_SESSION['error'] = 'Profile not found.';
                header('Location: /dashboard');
                exit;
            }

            // Get user's roles and permissions
            $userRoleData = $this->userModel->getRolesAndPermissions($userId);
            $user->roles = $userRoleData['roles'];
            $user->permissions = $userRoleData['permissions'];

            // Pass data for breadcrumb
            $data = [];

            include BASE_PATH . '/../Views/Users/profile.php';
        } catch (\Exception $e) {
            error_log("Exception in UserController::profile: " . $e->getMessage());
            $_SESSION['error'] = 'An error occurred while fetching your profile.';
            header('Location: /dashboard');
            exit;
        }
    }

    /**
     * Display user creation form
     * @param string $requestMethod
     * @param array $data
     * @throws RuntimeException
     */
    public function createForm(string $requestMethod, array $data): void
    {
        try {
            $this->authMiddleware->hasPermission('create_users');
            
            $companiesResult = $this->companyModel->getAll(['is_deleted' => 0], 1, 1000);
            $companies = $companiesResult['records'];
            $rolesResult = $this->roleModel->getAll(['is_deleted' => 0], 1, 1000);
            $roles = $rolesResult['records'];
            
            include BASE_PATH . '/../Views/Users/create.php';
        } catch (\Exception $e) {
            error_log("Exception in UserController::createForm: " . $e->getMessage());
            $_SESSION['error'] = 'An error occurred while loading the creation form.';
            header('Location: /users');
            exit;
        }
    }

    /**
     * Create new user
     * @param string $requestMethod
     * @param array $data
     * @throws RuntimeException
     */
    public function create(string $requestMethod, array $data): void
    {
        if ($requestMethod !== 'POST') {
            $this->createForm($requestMethod, $data);
            return;
        }

        try {
            $this->authMiddleware->hasPermission('create_users');

            $validator = new Validator($data, [
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|email|unique:users,email',
                'role_id' => 'required|integer|exists:roles,id',
                'company_id' => 'nullable|integer|exists:companies,id'
            ]);

            if ($validator->fails()) {
                throw new InvalidArgumentException(implode(', ', $validator->errors()));
            }

            $userData = [
                'first_name' => htmlspecialchars($data['first_name']),
                'last_name' => htmlspecialchars($data['last_name']),
                'email' => filter_var($data['email'], FILTER_SANITIZE_EMAIL),
                'role_id' => filter_var($data['role_id'], FILTER_VALIDATE_INT),
                'company_id' => !empty($data['company_id']) ? 
                    filter_var($data['company_id'], FILTER_VALIDATE_INT) : null,
                'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_ARGON2ID),
                'is_active' => false
            ];

            $userId = $this->userModel->create($userData);
            $activationToken = $this->userModel->generateActivationToken($userId);
            
            // Send activation email
            Email::sendActivationEmail($userData['email'], $activationToken);

            $_SESSION['success'] = 'User created successfully. An activation email has been sent.';
            header('Location: /users');
            exit;

        } catch (InvalidArgumentException $e) {
            $_SESSION['error'] = Config::getErrorMessage(
                $e,
                'UserController::create (validation)',
                $e->getMessage()
            );
            $_SESSION['form_data'] = $data;
            header('Location: /users/create');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = Config::getErrorMessage(
                $e,
                'UserController::create',
                'An error occurred while creating the user.'
            );
            header('Location: /users/create');
            exit;
        }
    }

    /**
     * Display user edit form
     * @param string $requestMethod
     * @param array $data
     * @throws RuntimeException
     */
    public function editForm(string $requestMethod, array $data): void
    {
        try {
            $this->authMiddleware->hasPermission('edit_users');

            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new InvalidArgumentException('Invalid user ID');
            }

            $user = $this->userModel->findWithDetails($id);
            if (!$user || $user->is_deleted) {
                throw new InvalidArgumentException('User not found');
            }

            $companiesResult = $this->companyModel->getAll(['is_deleted' => 0], 1, 1000);
            $companies = $companiesResult['records'];
            $rolesResult = $this->roleModel->getAll(['is_deleted' => 0], 1, 1000);
            $roles = $rolesResult['records'];

            include BASE_PATH . '/../Views/Users/edit.php';
        } catch (InvalidArgumentException $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /users');
            exit;
        } catch (\Exception $e) {
            error_log("Exception in UserController::editForm: " . $e->getMessage());
            $_SESSION['error'] = 'An error occurred while loading the edit form.';
            header('Location: /users');
            exit;
        }
    }

    /**
     * Update existing user
     * @param string $requestMethod
     * @param array $data
     * @throws RuntimeException
     */
    public function update(string $requestMethod, array $data): void
    {
        if ($requestMethod !== 'POST') {
            $this->editForm($requestMethod, $data);
            return;
        }

        try {
            $this->authMiddleware->hasPermission('edit_users');

            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new InvalidArgumentException('Invalid user ID');
            }

            $validator = new Validator($data, [
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => "required|email|unique:users,email,{$id}",
                'role_id' => 'required|integer|exists:roles,id',
                'company_id' => 'nullable|integer|exists:companies,id'
            ]);

            if ($validator->fails()) {
                throw new InvalidArgumentException(implode(', ', $validator->errors()));
            }

            $userData = [
                'first_name' => htmlspecialchars($data['first_name']),
                'last_name' => htmlspecialchars($data['last_name']),
                'email' => filter_var($data['email'], FILTER_SANITIZE_EMAIL),
                'role_id' => filter_var($data['role_id'], FILTER_VALIDATE_INT),
                'company_id' => !empty($data['company_id']) ? 
                    filter_var($data['company_id'], FILTER_VALIDATE_INT) : null
            ];

            $this->userModel->update($id, $userData);

            $_SESSION['success'] = 'User updated successfully.';
            header('Location: /users');
            exit;

        } catch (InvalidArgumentException $e) {
            $_SESSION['error'] = $e->getMessage();
            $_SESSION['form_data'] = $data;
            header("Location: /users/edit/{$id}");
            exit;
        } catch (\Exception $e) {
            error_log("Exception in UserController::update: " . $e->getMessage());
            $_SESSION['error'] = 'An error occurred while updating the user.';
            header("Location: /users/edit/{$id}");
            exit;
        }
    }

    /**
     * Delete user (soft delete)
     * @param string $requestMethod
     * @param array $data
     * @throws RuntimeException
     */
    public function delete(string $requestMethod, array $data): void
    {
        if ($requestMethod !== 'POST') {
            $_SESSION['error'] = 'Invalid request method.';
            header('Location: /users');
            exit;
        }

        try {
            $this->authMiddleware->hasPermission('delete_users');

            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new InvalidArgumentException('Invalid user ID');
            }

            // Check if user exists and is not already deleted
            $user = $this->userModel->find($id);
            if (!$user || $user->is_deleted) {
                throw new InvalidArgumentException('User not found');
            }

            // Prevent deleting own account
            if ($id === ($_SESSION['user']['id'] ?? null)) {
                throw new InvalidArgumentException('Cannot delete your own account');
            }

            $this->userModel->update($id, ['is_deleted' => true]);

            $_SESSION['success'] = 'User deleted successfully.';
            header('Location: /users');
            exit;

        } catch (InvalidArgumentException $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: /users');
            exit;
        } catch (\Exception $e) {
            error_log("Exception in UserController::delete: " . $e->getMessage());
            $_SESSION['error'] = 'An error occurred while deleting the user.';
            header('Location: /users');
            exit;
        }
    }
}